<?php

namespace App\Services\Wishlist;

use App\Enums\ConnectionStatus;
use App\Models\Game;
use App\Models\PlatformConnection;
use App\Models\User;
use App\Models\WishlistItem;
use App\Services\Gog\GogClient;
use App\Services\Gog\GogTokenManager;
use App\Services\Igdb\IgdbClient;
use App\Services\Library\GameFromIgdb;
use App\Services\Steam\SteamClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * V22: pull steam+gog wishlists, push local changes to GOG only.
 * Steam is read-only — no public write API exists.
 */
class WishlistSyncService
{
    /**
     * V46: genuine "no mapping" is cached only briefly so a later IGDB
     * backfill (B11-class: mapping added upstream) self-heals on the next
     * sync instead of being pinned forever. Positive hits stay cached
     * forever — those are stable.
     */
    private const NEGATIVE_CACHE_TTL = 6 * 3600;

    public function __construct(
        private readonly SteamClient $steam,
        private readonly GogClient $gog,
        private readonly GogTokenManager $gogTokens,
        private readonly IgdbClient $igdb,
        private readonly GameFromIgdb $games,
    ) {
    }

    public function sync(User $user): void
    {
        $steamConnection = $this->activeConnection($user, 'steam');
        $gogConnection = $this->activeConnection($user, 'gog');
        $gogToken = $gogConnection !== null
            ? $this->gogTokens->freshAccessToken($gogConnection)
            : null;

        if ($steamConnection !== null) {
            $this->pullSteam($user, $steamConnection);
        }

        if ($gogToken !== null) {
            $this->pullGog($user, $gogToken);
            $this->pushGog($user, $gogToken);
        }

        $this->reap($user);
    }

    private function activeConnection(User $user, string $platform): ?PlatformConnection
    {
        return $user->platformConnections()
            ->where('platform', $platform)
            ->where('status', '!=', ConnectionStatus::Disconnected)
            ->first();
    }

    private function pullSteam(User $user, PlatformConnection $connection): void
    {
        $appIds = $this->steam->getWishlist($connection->external_account_id);

        if ($appIds === null) {
            // Private wishlist — V15 pattern, skip without inventing data.
            return;
        }

        $seen = [];

        foreach ($appIds as $appId) {
            $game = $this->gameFromExternal(
                IgdbClient::EXTERNAL_STEAM,
                (string) $appId,
                fn () => $this->steam->appName($appId),
            );

            if ($game === null) {
                continue;
            }

            $seen[] = $game->id;
            $this->recordPresence($user, $game, 'steam');
        }

        $gone = WishlistItem::where('user_id', $user->id)
            ->where('steam_present', true)
            ->whereNotIn('game_id', $seen)
            ->get();

        $this->handleRemoteAbsence($gone, 'steam');
    }

    private function pullGog(User $user, string $accessToken): void
    {
        $productIds = $this->gog->getWishlist($accessToken);

        foreach ($productIds as $productId) {
            $game = $this->gameFromExternal(IgdbClient::EXTERNAL_GOG, $productId, fn () => null);

            if ($game === null) {
                continue;
            }

            $this->recordPresence($user, $game, 'gog', $productId);
        }

        // Keyed on product id, not resolved game — an unresolvable mapping
        // must never register as absence (that would skip the pending
        // remove push for tombstoned rows).
        $gone = WishlistItem::where('user_id', $user->id)
            ->where('gog_present', true)
            ->whereNotIn('gog_product_id', $productIds)
            ->get();

        $this->handleRemoteAbsence($gone, 'gog');
    }

    /**
     * True 2-way: an item we knew was on a platform but is no longer there
     * was removed remotely. Propagate — delete the local wish unless the
     * other platform still lists it or a tombstone was already pending
     * (then the remote removal simply completes the tombstone's goal).
     *
     * @param  \Illuminate\Support\Collection<int, WishlistItem>  $gone
     */
    private function handleRemoteAbsence($gone, string $platform): void
    {
        $flag = $platform.'_present';
        $otherFlag = $platform === 'steam' ? 'gog_present' : 'steam_present';

        foreach ($gone as $item) {
            if ($item->suppressed_at !== null || $item->{$otherFlag}) {
                $item->update([$flag => false]);
            } else {
                $item->delete();
            }
        }
    }

    /**
     * V22 push: local wishes → GOG adds; tombstoned GOG wishes → removes.
     * Presence flags flip only after the remote call succeeds, so each
     * state change writes remotely at most once.
     */
    private function pushGog(User $user, string $accessToken): void
    {
        $pending = WishlistItem::where('user_id', $user->id)
            ->whereNull('suppressed_at')
            ->where('gog_present', false)
            ->with('game')
            ->get();

        foreach ($pending as $item) {
            $productId = $item->gog_product_id ?? $this->resolveGogProductId($item->game);

            if ($productId === null) {
                continue;
            }

            $this->gog->addToWishlist($accessToken, $productId);
            $item->update([
                'gog_present' => true,
                'gog_product_id' => $productId,
                'synced_at' => Date::now(),
            ]);
        }

        $tombstoned = WishlistItem::where('user_id', $user->id)
            ->whereNotNull('suppressed_at')
            ->where('gog_present', true)
            ->whereNotNull('gog_product_id')
            ->get();

        foreach ($tombstoned as $item) {
            $this->gog->removeFromWishlist($accessToken, $item->gog_product_id);
            $item->update(['gog_present' => false, 'synced_at' => Date::now()]);
        }
    }

    /**
     * GOG product id for a canonical game via IGDB's external mapping,
     * cached forever (stable data, V4 spirit).
     */
    private function resolveGogProductId(Game $game): ?string
    {
        if ($game->igdb_id === null) {
            return null;
        }

        // `:v2:` abandons pre-B11 poisoned keys (empty-string misses cached
        // forever under the wrong `category` query — T45/V45).
        $key = 'igdb-external-uid:v2:'.IgdbClient::EXTERNAL_GOG.":{$game->igdb_id}";
        $cached = Cache::get($key);

        if ($cached !== null) {
            return $cached === '' ? null : $cached;
        }

        $uid = $this->igdb->externalUid($game->igdb_id, IgdbClient::EXTERNAL_GOG);

        if ($uid !== null) {
            Cache::forever($key, $uid);

            return $uid;
        }

        Cache::put($key, '', self::NEGATIVE_CACHE_TTL);

        return null;
    }

    /**
     * Tombstones stay while any platform still lists the item (blocking
     * re-import); once nothing does, the row can finally go.
     */
    private function reap(User $user): void
    {
        WishlistItem::where('user_id', $user->id)
            ->whereNotNull('suppressed_at')
            ->where('steam_present', false)
            ->where('gog_present', false)
            ->delete();
    }

    /**
     * @param  callable(): ?string  $fallbackTitle
     */
    private function gameFromExternal(int $category, string $uid, callable $fallbackTitle): ?Game
    {
        try {
            $igdbId = $this->resolveExternalIgdbId($category, $uid);

            if ($igdbId !== null) {
                return $this->games->findOrCreate($igdbId);
            }

            // V11 spirit: no IGDB mapping still lands a provisional row when a
            // store title is available.
            $title = $fallbackTitle();

            if ($title === null) {
                return null;
            }

            return Game::firstOrCreate(['title' => $title, 'igdb_id' => null]);
        } catch (Throwable $e) {
            // V46: one item's IGDB/Twitch/Steam-store failure skips that item,
            // never aborts the batch (B12). The failure is not written to
            // cache (V26 MISS-on-exception) — the item retries next sync.
            Log::warning('Wishlist external resolution failed, skipping item', [
                'category' => $category,
                'uid' => $uid,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * IGDB game id for a store id (V4 cache). Positive mappings are stable →
     * cached forever; genuine misses use a short TTL (V46) so a later IGDB
     * backfill self-heals rather than sticking forever. `:v2:` abandons the
     * pre-B11 poisoned keys (0-cached forever under the wrong `category`
     * query — T45/V45).
     */
    private function resolveExternalIgdbId(int $category, string $uid): ?int
    {
        $key = "igdb-external:v2:{$category}:{$uid}";
        $cached = Cache::get($key);

        if ($cached !== null) {
            return $cached === 0 ? null : (int) $cached;
        }

        $igdbId = $this->igdb->gameIdFromExternal($category, $uid);

        if ($igdbId !== null) {
            Cache::forever($key, $igdbId);

            return $igdbId;
        }

        Cache::put($key, 0, self::NEGATIVE_CACHE_TTL);

        return null;
    }

    private function recordPresence(User $user, Game $game, string $platform, ?string $productId = null): void
    {
        // V21: owned games never enter the wishlist.
        if ($user->ownedGames()->where('game_id', $game->id)->exists()) {
            return;
        }

        $item = WishlistItem::firstOrNew([
            'user_id' => $user->id,
            'game_id' => $game->id,
        ]);

        if (! $item->exists) {
            // New import — first seen on this platform (tombstones for
            // deleted rows were reaped only once platforms dropped them,
            // so an existing tombstone keeps blocking this create below).
            $item->added_at = Date::now();
            $item->origin = $platform;
        }

        if ($item->exists && $item->suppressed_at !== null) {
            // V22: locally deleted — refresh presence flags only, never
            // resurrect the wish.
            $item->{$platform.'_present'} = true;
            if ($productId !== null) {
                $item->gog_product_id = $productId;
            }
            $item->save();

            return;
        }

        $item->{$platform.'_present'} = true;
        if ($productId !== null) {
            $item->gog_product_id = $productId;
        }
        $item->synced_at = Date::now();
        $item->save();
    }
}
