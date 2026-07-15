<?php

namespace App\Http\Controllers\Library;

use App\Http\Controllers\Controller;
use App\Models\Game;
use App\Services\Library\GameIgdbRefresh;
use App\Services\Library\LibraryQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class IgdbRefreshController extends Controller
{
    /**
     * I.api T30/V35: POST /api/library/:game_id/refresh-igdb — re-fetches
     * an already-matched game's IGDB data on demand. Synchronous, mirrors
     * the existing inline IGDB call in manual-add (T14) — ⊥ V8, which
     * scopes bulk connection sync only.
     */
    public function store(Request $request, Game $game, GameIgdbRefresh $refresh, LibraryQuery $library): JsonResponse
    {
        $ownsGame = $request->user()->ownedGames()->where('game_id', $game->id)->exists();

        abort_unless($ownsGame, Response::HTTP_NOT_FOUND);
        abort_if($game->igdb_id === null, Response::HTTP_UNPROCESSABLE_ENTITY, 'Game has no IGDB match yet — fix match first.');

        $refreshed = $refresh->refresh($game);

        abort_if($refreshed === null, Response::HTTP_NOT_FOUND, 'IGDB no longer has this game.');

        return response()->json($library->forGame($request->user(), $refreshed));
    }
}
