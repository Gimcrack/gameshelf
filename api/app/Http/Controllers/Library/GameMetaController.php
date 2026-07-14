<?php

namespace App\Http\Controllers\Library;

use App\Enums\GameStatus;
use App\Http\Controllers\Controller;
use App\Models\Game;
use App\Models\UserGameMeta;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

class GameMetaController extends Controller
{
    /**
     * Upsert the caller's meta for a game in their library (V6: this table
     * is user-owned; sync never writes it). Partial updates — omitted
     * fields keep their values.
     */
    public function update(Request $request, Game $game): JsonResponse
    {
        $ownsGame = $request->user()
            ->ownedGames()
            ->where('game_id', $game->id)
            ->exists();

        abort_unless($ownsGame, Response::HTTP_NOT_FOUND);

        $validated = $request->validate([
            'status' => ['sometimes', Rule::enum(GameStatus::class)],
            'tags' => ['sometimes', 'array', 'max:50'],
            'tags.*' => ['string', 'max:40'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'rating' => ['sometimes', 'nullable', 'integer', 'between:1,5'],
        ]);

        $meta = UserGameMeta::firstOrNew([
            'user_id' => $request->user()->id,
            'game_id' => $game->id,
        ]);

        $meta->fill($validated)->save();

        return response()->json($meta->fresh());
    }
}
