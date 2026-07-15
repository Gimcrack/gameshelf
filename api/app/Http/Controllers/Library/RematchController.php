<?php

namespace App\Http\Controllers\Library;

use App\Http\Controllers\Controller;
use App\Models\Game;
use App\Services\Library\GameRematch;
use App\Services\Library\LibraryQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class RematchController extends Controller
{
    /**
     * I.api T29/V34: POST /api/library/:game_id/rematch — repoints the
     * caller's entry to a different IGDB game, correcting a wrong or
     * missing auto-match. Candidate search reuses GET /api/discover/search.
     */
    public function store(Request $request, Game $game, GameRematch $rematch, LibraryQuery $library): JsonResponse
    {
        $ownsGame = $request->user()->ownedGames()->where('game_id', $game->id)->exists();

        abort_unless($ownsGame, Response::HTTP_NOT_FOUND);

        $validated = $request->validate([
            'igdb_id' => ['required', 'integer', 'min:1'],
        ]);

        $target = $rematch->apply($request->user(), $game, (int) $validated['igdb_id']);

        if ($target === null) {
            throw ValidationException::withMessages([
                'igdb_id' => ['No IGDB game matches that id.'],
            ]);
        }

        return response()->json($library->forGame($request->user(), $target));
    }
}
