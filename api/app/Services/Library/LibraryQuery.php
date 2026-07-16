<?php

namespace App\Services\Library;

use App\Models\Game;
use App\Models\GameAchievementDef;
use App\Models\OwnedGame;
use App\Models\OwnedGameAchievement;
use App\Models\User;
use App\Models\UserGameMeta;
use App\Models\WishlistItem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Date;

/**
 * V1: owned_games keeps one row per (user, platform, game); this service
 * dedupes to one entry per game at query time.
 *
 * V42: the library is a query-time union — owned_games ∪ wishlist_items ∪
 * meta-orphans — with a library_status per entry. Data-layer disjointness
 * (V21, V10) is untouched; only this read path assembles the union.
 */
class LibraryQuery
{
    /**
     * V42/V58: statuses backed by owned_games rows — the only ones stats,
     * backlog and system collections count. Shared counts same as owned+free
     * (fully playable, T60).
     */
    private const OWNED_STATUSES = ['owned', 'free', 'shared'];

    /**
     * T67/V64: platforms with an achievement source — GOG is categorically
     * excluded (no external read API exists).
     */
    private const ACHIEVEMENT_PLATFORMS = ['steam', 'xbox'];
    /**
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>
     */
    public function forUser(User $user, array $filters): array
    {
        return $this->sort($this->filter($this->entriesFor($user), $filters), $filters)
            ->values()
            ->all();
    }

    /**
     * T28: distinct genre/theme/keyword/game_mode/platform values across
     * the caller's (non-hidden, V28/V36) library — feeds the left-sidebar
     * checkbox filters. Static full vocabulary, ⊥ responsive to other
     * active filter selections (V36).
     *
     * @return array<string, list<string>>
     */
    public function facetsForUser(User $user): array
    {
        // I.api/V36: facet vocabulary comes from owned games only —
        // wishlist/none union rows (V42) don't widen it.
        $entries = $this->entriesFor($user)->reject(
            fn (array $e) => $e['hidden'] || ! in_array($e['library_status'], self::OWNED_STATUSES, true),
        );

        return [
            'genres' => $this->distinctValues($entries, 'genres'),
            'themes' => $this->distinctValues($entries, 'themes'),
            'keywords' => $this->distinctValues($entries, 'keywords'),
            'game_modes' => $this->distinctValues($entries, 'game_modes'),
            'platforms' => $entries
                ->flatMap(fn (array $e) => array_column($e['platforms'], 'platform'))
                ->unique()
                ->sort()
                ->values()
                ->all(),
            'esrb_ratings' => $this->esrbRatings($entries),
        ];
    }

    /**
     * T36: distinct ESRB values in-library, + `none` sentinel when unrated
     * (null, V33) games exist — sentinel is facet/query layer only.
     *
     * @param  Collection<int, array<string, mixed>>  $entries
     * @return list<string>
     */
    private function esrbRatings(Collection $entries): array
    {
        $ratings = $entries->pluck('esrb_rating')
            ->filter(fn (?string $v) => $v !== null)
            ->unique()
            ->sort()
            ->values();

        return $entries->contains(fn (array $e) => $e['esrb_rating'] === null)
            ? [...$ratings, 'none']
            : $ratings->all();
    }

    /**
     * V1/V6: dedupes owned_games to one entry per game, joining user meta
     * at read time — the shared base both forUser and facetsForUser build on.
     *
     * V42: unioned with wishlist_items and meta-orphans (meta rows whose
     * game has no owned or wishlist row — they survive V6/V24).
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function entriesFor(User $user): Collection
    {
        $metaByGame = UserGameMeta::where('user_id', $user->id)
            ->with('game')
            ->get()
            ->keyBy('game_id');

        $ownedRows = OwnedGame::query()
            ->where('user_id', $user->id)
            ->with(['game', 'connection.familyMember'])
            ->withCount(['achievements as unlocked_achievement_count' => fn ($q) => $q->where('unlocked', true)])
            ->get();

        $defTotals = $this->achievementDefTotals($ownedRows);

        $owned = $ownedRows
            ->groupBy('game_id')
            ->map(fn (Collection $group) => $this->entry($group, $metaByGame->get($group->first()->game_id), $defTotals))
            ->values();

        // V21: disjoint from owned at the data layer — no overlap check needed.
        $wishlist = WishlistItem::query()
            ->where('user_id', $user->id)
            ->whereNull('suppressed_at')
            ->with('game')
            ->get()
            ->map(fn (WishlistItem $item) => $this->virtualEntry(
                $item->game,
                $metaByGame->get($item->game_id),
                'wishlist',
                $item->added_at,
            ));

        $coveredGameIds = $owned->pluck('id')->concat($wishlist->pluck('id'));

        $orphans = $metaByGame
            ->whereNotIn('game_id', $coveredGameIds)
            ->values()
            ->map(fn (UserGameMeta $meta) => $this->virtualEntry(
                $meta->game,
                $meta,
                'none',
                $meta->created_at,
            ));

        return $owned->concat($wishlist)->concat($orphans)->values();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $entries
     * @return list<string>
     */
    private function distinctValues(Collection $entries, string $field): array
    {
        return $entries->flatMap(fn (array $e) => $e[$field])
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    /**
     * I.api: GET /api/library/:game_id — same entry shape as the list, for
     * one game. Null when the caller owns no row for it (404 upstream).
     *
     * @return array<string, mixed>|null
     */
    public function forGame(User $user, Game $game): ?array
    {
        $group = OwnedGame::query()
            ->where('user_id', $user->id)
            ->where('game_id', $game->id)
            ->with(['game', 'connection.familyMember'])
            ->withCount(['achievements as unlocked_achievement_count' => fn ($q) => $q->where('unlocked', true)])
            ->get();

        $meta = UserGameMeta::where('user_id', $user->id)->where('game_id', $game->id)->first();

        if ($group->isNotEmpty()) {
            return $this->entry($group, $meta, $this->achievementDefTotals($group));
        }

        // V42: union rows resolve here too — a wishlist or meta-orphan
        // entry is fetchable, not a 404.
        $wish = WishlistItem::query()
            ->where('user_id', $user->id)
            ->where('game_id', $game->id)
            ->whereNull('suppressed_at')
            ->first();

        if ($wish !== null) {
            return $this->virtualEntry($game, $meta, 'wishlist', $wish->added_at);
        }

        if ($meta !== null) {
            return $this->virtualEntry($game, $meta, 'none', $meta->created_at);
        }

        return null;
    }

    /**
     * @param  Collection<int, OwnedGame>  $group
     * @param  array<string, int>  $defTotals
     * @return array<string, mixed>
     */
    private function entry(Collection $group, ?UserGameMeta $meta, array $defTotals): array
    {
        $knownPlaytimes = $group->pluck('playtime_minutes')->reject(fn ($v) => $v === null);

        return [
            ...$this->gameFields($group->first()->game),
            ...$this->metaFields($meta),
            // V42/V58 precedence: any plain-owned row (¬free_to_play∧¬shared)
            // wins outright; else any free_to_play row wins over shared.
            'library_status' => match (true) {
                $group->contains(fn (OwnedGame $owned) => ! $owned->free_to_play && ! $owned->shared) => 'owned',
                $group->contains(fn (OwnedGame $owned) => $owned->free_to_play) => 'free',
                default => 'shared',
            },
            'platforms' => $group->map(fn (OwnedGame $owned) => [
                'platform' => $owned->connection->platform,
                // V13: disconnected status flows through for UI badges.
                'connection_status' => $owned->connection->status->value,
                'playtime_minutes' => $owned->playtime_minutes,
                'last_played_at' => $owned->last_played_at?->toIso8601String(),
                // T26/V31: Steam-only, null = never successfully checked.
                'deck_status' => $owned->deck_status?->value,
                // T60/V58: attribution for shared rows, null otherwise.
                'shared_by' => $owned->shared ? $owned->connection->familyMember?->persona_name : null,
            ])->values()->all(),
            // V12: all-null playtime stays null (unknown), never 0.
            'total_playtime_minutes' => $knownPlaytimes->isEmpty() ? null : $knownPlaytimes->sum(),
            'last_played_at' => $group->max('last_played_at')?->toIso8601String(),
            'added_at' => $group->min('added_at')?->toIso8601String(),
            // T70/V67: null when no achievement-capable owning row exists.
            'achievements_summary' => $this->achievementsSummary($group, $defTotals),
        ];
    }

    /**
     * V42: wishlist / meta-orphan union rows — no owning platforms, so
     * platforms stay empty and playtime null (I.api).
     *
     * @return array<string, mixed>
     */
    private function virtualEntry(Game $game, ?UserGameMeta $meta, string $libraryStatus, ?Carbon $addedAt): array
    {
        return [
            ...$this->gameFields($game),
            ...$this->metaFields($meta),
            'library_status' => $libraryStatus,
            'platforms' => [],
            'total_playtime_minutes' => null,
            'last_played_at' => null,
            'added_at' => $addedAt?->toIso8601String(),
            // T70/V67: wishlist/none entries have no owning platform at all.
            'achievements_summary' => null,
        ];
    }

    /**
     * T70/V63/V67: aggregated across every achievement-capable owning row
     * (a game owned on both Steam and Xbox sums both) — null when none of
     * the owning rows are on an achievement-capable platform (V64).
     *
     * @param  Collection<int, OwnedGame>  $group
     * @param  array<string, int>  $defTotals
     * @return array{unlocked: int, total: int}|null
     */
    private function achievementsSummary(Collection $group, array $defTotals): ?array
    {
        $capable = $group->filter(
            fn (OwnedGame $og) => in_array($og->connection->platform, self::ACHIEVEMENT_PLATFORMS, true),
        );

        if ($capable->isEmpty()) {
            return null;
        }

        return [
            'unlocked' => $capable->sum(fn (OwnedGame $og) => $og->unlocked_achievement_count),
            'total' => $capable->sum(
                fn (OwnedGame $og) => $defTotals[$og->connection->platform.'|'.$og->platform_game_id] ?? 0,
            ),
        ];
    }

    /**
     * Prefetches def counts per (platform, platform_game_id) in 1 query,
     * avoiding an N+1 across every owned row when building entries.
     *
     * @param  Collection<int, OwnedGame>  $ownedRows
     * @return array<string, int>
     */
    private function achievementDefTotals(Collection $ownedRows): array
    {
        $platformGameIds = $ownedRows
            ->filter(fn (OwnedGame $og) => in_array($og->connection->platform, self::ACHIEVEMENT_PLATFORMS, true))
            ->pluck('platform_game_id')
            ->unique();

        if ($platformGameIds->isEmpty()) {
            return [];
        }

        return GameAchievementDef::query()
            ->whereIn('platform', self::ACHIEVEMENT_PLATFORMS)
            ->whereIn('platform_game_id', $platformGameIds)
            ->get()
            ->groupBy(fn (GameAchievementDef $d) => $d->platform.'|'.$d->platform_game_id)
            ->map->count()
            ->all();
    }

    /**
     * I.api T70: GET /api/library/:game_id/achievements — the full list.
     * Null (404 upstream) when the game has no achievement-capable owning
     * row: not in the caller's library at all, gog/manual-only (V64), or a
     * wishlist/none union entry (V67 — mirrors V53/V57 unowned gating).
     *
     * @return list<array<string, mixed>>|null
     */
    public function achievementsForGame(User $user, Game $game): ?array
    {
        $capable = OwnedGame::query()
            ->where('user_id', $user->id)
            ->where('game_id', $game->id)
            ->with('connection')
            ->get()
            ->filter(fn (OwnedGame $og) => in_array($og->connection->platform, self::ACHIEVEMENT_PLATFORMS, true));

        if ($capable->isEmpty()) {
            return null;
        }

        return $capable
            ->flatMap(function (OwnedGame $og) {
                $defs = GameAchievementDef::query()
                    ->where('platform', $og->connection->platform)
                    ->where('platform_game_id', $og->platform_game_id)
                    ->get();

                $unlocksByDefId = OwnedGameAchievement::query()
                    ->where('owned_game_id', $og->id)
                    ->get()
                    ->keyBy('game_achievement_def_id');

                return $defs->map(function (GameAchievementDef $def) use ($og, $unlocksByDefId) {
                    $unlock = $unlocksByDefId->get($def->id);

                    return [
                        'platform' => $og->connection->platform,
                        'name' => $def->name,
                        'description' => $def->description,
                        'icon_url' => $def->icon_url,
                        'points' => $def->points,
                        'unlocked' => $unlock?->unlocked ?? false,
                        'unlocked_at' => $unlock?->unlocked_at?->toIso8601String(),
                    ];
                });
            })
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function gameFields(Game $game): array
    {
        return [
            'id' => $game->id,
            'igdb_id' => $game->igdb_id,
            'title' => $game->title,
            'cover_url' => $game->cover_url,
            'genres' => $game->genres ?? [],
            'themes' => $game->themes ?? [],
            'keywords' => $game->keywords ?? [],
            'game_modes' => $game->game_modes ?? [],
            'release_date' => $game->release_date?->toDateString(),
            'time_to_beat_minutes' => $game->time_to_beat_minutes,
            // T27/V33: null = unrated | non-ESRB-market, never a placeholder string.
            'esrb_rating' => $game->esrb_rating,
            // T27/V32: null = not yet fetched, distinct from false.
            'multiplayer' => $game->multiplayer,
            'coop' => $game->coop,
            'local_multiplayer' => $game->local_multiplayer,
            'local_coop' => $game->local_coop,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function metaFields(?UserGameMeta $meta): array
    {
        return [
            // No meta row means untouched — status defaults to unplayed.
            'status' => $meta?->status->value ?? 'unplayed',
            // V12: only a real meta row counts as user-declared for the
            // unplayed collection; the default above never does.
            'status_declared' => $meta !== null,
            'tags' => $meta?->tags ?? [],
            'notes' => $meta?->notes,
            'rating' => $meta?->rating,
            // V28: no meta row means never hidden.
            'hidden' => $meta?->hidden ?? false,
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $entries
     * @param  array<string, mixed>  $filters
     * @return Collection<int, array<string, mixed>>
     */
    private function filter(Collection $entries, array $filters): Collection
    {
        return $entries
            // V28: hidden games excluded by default; include_hidden=1 reveals them.
            ->when(empty($filters['include_hidden']), fn (Collection $c) => $c->filter(
                fn (array $e) => ! $e['hidden'],
            ))
            // T28: multi-select via the same comma-string-or-array
            // convention as `tags` (valueList) — a single value behaves
            // exactly as before (backward compatible with T22 callers and
            // saved custom-collection filter JSON, T23).
            ->when(isset($filters['platform']), fn (Collection $c) => $c->filter(
                fn (array $e) => array_intersect(
                    $this->valueList($filters['platform']),
                    array_column($e['platforms'], 'platform'),
                ) !== [],
            ))
            ->when(isset($filters['genre']), fn (Collection $c) => $c->filter(
                fn (array $e) => array_intersect($this->valueList($filters['genre']), $e['genres']) !== [],
            ))
            ->when(isset($filters['theme']), fn (Collection $c) => $c->filter(
                fn (array $e) => array_intersect($this->valueList($filters['theme']), $e['themes']) !== [],
            ))
            ->when(isset($filters['keyword']), fn (Collection $c) => $c->filter(
                fn (array $e) => array_intersect($this->valueList($filters['keyword']), $e['keywords']) !== [],
            ))
            ->when(isset($filters['game_mode']), fn (Collection $c) => $c->filter(
                fn (array $e) => array_intersect($this->valueList($filters['game_mode']), $e['game_modes']) !== [],
            ))
            // T28: title substring, case-insensitive.
            ->when(! empty($filters['q']), fn (Collection $c) => $c->filter(
                fn (array $e) => str_contains(mb_strtolower($e['title']), mb_strtolower(trim($filters['q']))),
            ))
            // T26/V31: matches any owning platform row whose deck_status is
            // in the selected set; null (never checked) never matches.
            ->when(isset($filters['deck_status']), fn (Collection $c) => $c->filter(
                fn (array $e) => array_intersect(
                    $filters['deck_status'],
                    array_filter(array_column($e['platforms'], 'deck_status')),
                ) !== [],
            ))
            // T36: multi-select; `none` sentinel matches unrated (null) —
            // query-layer only, storage stays null (V33). valueList keeps
            // legacy single-string values (saved collection filters, T23)
            // working.
            ->when(isset($filters['esrb']), fn (Collection $c) => $c->filter(
                fn (array $e) => in_array($e['esrb_rating'] ?? 'none', $this->valueList($filters['esrb']), true),
            ))
            // T38/V42: multi-select on the union's per-entry status.
            ->when(isset($filters['library_status']), fn (Collection $c) => $c->filter(
                fn (array $e) => in_array($e['library_status'], $this->valueList($filters['library_status']), true),
            ))
            // T40: personal rating multi-select; `none` matches unrated
            // (rating null). String-cast — entry rating is an int, query
            // values arrive as strings; mirrors the esrb `none` sentinel.
            ->when(isset($filters['rating']), fn (Collection $c) => $c->filter(
                fn (array $e) => in_array((string) ($e['rating'] ?? 'none'), $this->valueList($filters['rating']), true),
            ))
            // T27/V32: equality against the derived flag; null (best-effort
            // miss) matches neither an explicit true nor false query.
            ->when(isset($filters['multiplayer']), fn (Collection $c) => $c->filter(
                fn (array $e) => $e['multiplayer'] === $filters['multiplayer'],
            ))
            ->when(isset($filters['coop']), fn (Collection $c) => $c->filter(
                fn (array $e) => $e['coop'] === $filters['coop'],
            ))
            ->when(isset($filters['local_multiplayer']), fn (Collection $c) => $c->filter(
                fn (array $e) => $e['local_multiplayer'] === $filters['local_multiplayer'],
            ))
            ->when(isset($filters['local_coop']), fn (Collection $c) => $c->filter(
                fn (array $e) => $e['local_coop'] === $filters['local_coop'],
            ))
            // V29: manual collection membership, synthesized internally by
            // LibraryController::resolveCollection — never a public param.
            ->when(isset($filters['game_ids']), fn (Collection $c) => $c->filter(
                fn (array $e) => in_array($e['id'], $filters['game_ids'], true),
            ))
            ->when(isset($filters['playtime_min']), fn (Collection $c) => $c->filter(
                fn (array $e) => $e['total_playtime_minutes'] !== null
                    && $e['total_playtime_minutes'] >= (int) $filters['playtime_min'],
            ))
            ->when(isset($filters['playtime_max']), fn (Collection $c) => $c->filter(
                fn (array $e) => $e['total_playtime_minutes'] !== null
                    && $e['total_playtime_minutes'] <= (int) $filters['playtime_max'],
            ))
            // V12: unplayed means known-zero; unknown (null) is excluded.
            ->when(! empty($filters['unplayed']), fn (Collection $c) => $c->filter(
                fn (array $e) => $e['total_playtime_minutes'] === 0,
            ))
            ->when(isset($filters['status']), fn (Collection $c) => $c->filter(
                fn (array $e) => $e['status'] === $filters['status'],
            ))
            ->when(isset($filters['tags']), fn (Collection $c) => $c->filter(
                fn (array $e) => array_intersect($this->valueList($filters['tags']), $e['tags']) !== [],
            ))
            ->when(isset($filters['collection']), fn (Collection $c) => $this->systemCollection(
                $c,
                $filters['collection'],
            ));
    }

    /**
     * @return list<string>
     */
    private function valueList(string|array $value): array
    {
        return is_array($value) ? array_values($value) : array_map('trim', explode(',', $value));
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $entries
     * @return Collection<int, array<string, mixed>>
     */
    private function systemCollection(Collection $entries, string $slug): Collection
    {
        // V42: system collections count owned+free only — wishlist and
        // meta-orphan union rows never qualify (V21 stats-layer exclusion).
        $entries = $entries->filter(
            fn (array $e) => in_array($e['library_status'], self::OWNED_STATUSES, true),
        );

        return match ($slug) {
            // V12: known-zero playtime or user-declared unplayed; null
            // playtime alone stays out.
            'unplayed' => $entries->filter(
                fn (array $e) => $e['total_playtime_minutes'] === 0
                    || ($e['status_declared'] && $e['status'] === 'unplayed'),
            ),
            // I.api: played, untouched 6+ months, not finished.
            'abandoned' => $entries->filter(
                fn (array $e) => $e['last_played_at'] !== null
                    && Date::parse($e['last_played_at'])->lte(Date::now()->subMonths(SystemCollections::ABANDONED_AFTER_MONTHS))
                    && $e['status'] !== 'finished',
            ),
            // §C: conditional on time-to-beat data being present.
            'quick_wins' => $entries->filter(
                fn (array $e) => $e['time_to_beat_minutes'] !== null
                    && $e['time_to_beat_minutes'] < SystemCollections::QUICK_WIN_MAX_MINUTES,
            ),
        };
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $entries
     * @param  array<string, mixed>  $filters
     * @return Collection<int, array<string, mixed>>
     */
    private function sort(Collection $entries, array $filters): Collection
    {
        $sort = $filters['sort'] ?? 'alpha';
        $descending = ($filters['order'] ?? 'asc') === 'desc';

        if ($sort === 'alpha') {
            return $entries->sortBy('title', SORT_NATURAL | SORT_FLAG_CASE, $descending);
        }

        $key = match ($sort) {
            'playtime' => 'total_playtime_minutes',
            'last_played' => 'last_played_at',
            'added' => 'added_at',
        };

        // Nulls (unknown values) always sort last, regardless of direction.
        [$known, $unknown] = $entries->partition(fn (array $e) => $e[$key] !== null);

        return $known->sortBy(fn (array $e) => $e[$key], SORT_REGULAR, $descending)
            ->concat($unknown);
    }
}
