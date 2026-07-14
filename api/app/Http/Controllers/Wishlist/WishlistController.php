<?php

namespace App\Http\Controllers\Wishlist;

use App\Http\Controllers\Controller;
use App\Models\Game;
use App\Models\WishlistItem;
use App\Services\Library\GameFromIgdb;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class WishlistController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $items = WishlistItem::where('user_id', $request->user()->id)
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
            return response()->json(self::hit($existing));
        }

        $item = WishlistItem::create([
            'user_id' => $request->user()->id,
            'game_id' => $game->id,
            'added_at' => now(),
        ]);

        return response()->json(self::hit($item->load('game')), Response::HTTP_CREATED);
    }

    public function destroy(Request $request, Game $game): Response
    {
        $deleted = WishlistItem::where('user_id', $request->user()->id)
            ->where('game_id', $game->id)
            ->delete();

        abort_unless($deleted > 0, Response::HTTP_NOT_FOUND);

        return response()->noContent();
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
        ];
    }
}
