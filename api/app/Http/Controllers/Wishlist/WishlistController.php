<?php

namespace App\Http\Controllers\Wishlist;

use App\Http\Controllers\Controller;
use App\Jobs\SyncWishlist;
use App\Models\Game;
use App\Models\WishlistItem;
use App\Services\Library\GameFromIgdb;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class WishlistController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        // V22: tombstoned rows are sync bookkeeping, never user-visible.
        $items = WishlistItem::where('user_id', $request->user()->id)
            ->whereNull('suppressed_at')
            ->with('game')
            ->orderByDesc('added_at')
            ->get()
            ->map(fn (WishlistItem $item) => self::hit($item));

        return response()->json($items);
    }

    /**
     * V21: owned games are never wishlisted — 200 no-op with in_library.
     */
    public function store(Request $request, GameFromIgdb $games): JsonResponse
    {
        $validated = $request->validate([
            'igdb_id' => ['required', 'integer', 'min:1'],
        ]);

        $game = $games->findOrCreate((int) $validated['igdb_id']);

        if ($game === null) {
            throw ValidationException::withMessages([
                'igdb_id' => ['No IGDB game matches that id.'],
            ]);
        }

        $owned = $request->user()->ownedGames()->where('game_id', $game->id)->exists();

        if ($owned) {
            return response()->json([...$game->toArray(), 'in_library' => true]);
        }

        $existing = WishlistItem::where('user_id', $request->user()->id)
            ->where('game_id', $game->id)
            ->first();

        if ($existing !== null) {
            // Re-adding a locally deleted wish revives it (clears the
            // V22 tombstone) instead of failing on the unique key.
            if ($existing->suppressed_at !== null) {
                $existing->update(['suppressed_at' => null, 'added_at' => now()]);
            }

            return response()->json(self::hit($existing->fresh('game')));
        }

        $item = WishlistItem::create([
            'user_id' => $request->user()->id,
            'game_id' => $game->id,
            'added_at' => now(),
        ]);

        return response()->json(self::hit($item->load('game')), Response::HTTP_CREATED);
    }

    /**
     * V22: platform-present wishes tombstone (sync propagates the removal
     * and reaps later); purely local wishes delete outright.
     */
    public function destroy(Request $request, Game $game): Response
    {
        $item = WishlistItem::where('user_id', $request->user()->id)
            ->where('game_id', $game->id)
            ->whereNull('suppressed_at')
            ->first();

        abort_if($item === null, Response::HTTP_NOT_FOUND);

        if ($item->steam_present || $item->gog_present) {
            $item->update(['suppressed_at' => now()]);
        } else {
            $item->delete();
        }

        return response()->noContent();
    }

    /**
     * V8/V22: queue the sync; throttled like connection sync (≥5 min gap).
     */
    public function sync(Request $request): JsonResponse
    {
        $throttleKey = "wishlist-sync:{$request->user()->id}";

        if (! RateLimiter::attempt($throttleKey, 1, fn () => null, 300)) {
            return response()->json([
                'message' => 'Wishlist sync already requested recently. Try again in a few minutes.',
            ], Response::HTTP_TOO_MANY_REQUESTS);
        }

        SyncWishlist::dispatch($request->user()->id);

        return response()->json(['message' => 'Wishlist sync queued.'], Response::HTTP_ACCEPTED);
    }

    /**
     * @return array<string, mixed>
     */
    public static function hit(WishlistItem $item): array
    {
        return [
            'game_id' => $item->game_id,
            'igdb_id' => $item->game->igdb_id,
            'title' => $item->game->title,
            'cover_url' => $item->game->cover_url,
            'genres' => $item->game->genres ?? [],
            'release_date' => $item->game->release_date?->toDateString(),
            'time_to_beat_minutes' => $item->game->time_to_beat_minutes,
            'added_at' => $item->added_at->toIso8601String(),
            'origin' => $item->origin,
            'steam_present' => $item->steam_present,
            'gog_present' => $item->gog_present,
            'synced_at' => $item->synced_at?->toIso8601String(),
        ];
    }
}
