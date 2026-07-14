<?php

namespace App\Http\Controllers\Library;

use App\Http\Controllers\Controller;
use App\Models\Game;
use App\Services\Library\GameFromIgdb;
use App\Services\Library\ManualLibrary;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class ManualEntryController extends Controller
{
    /**
     * I.api: POST /api/library {igdb_id} → manual owned entry. 201 fresh,
     * 200 when the game is already in the library (V19).
     */
    public function store(
        Request $request,
        GameFromIgdb $games,
        ManualLibrary $manual,
    ): JsonResponse {
        $validated = $request->validate([
            'igdb_id' => ['required', 'integer', 'min:1'],
        ]);

        $game = $games->findOrCreate((int) $validated['igdb_id']);

        if ($game === null) {
            throw ValidationException::withMessages([
                'igdb_id' => ['No IGDB game matches that id.'],
            ]);
        }

        [, $created] = $manual->add($request->user(), $game);

        return response()->json(
            $game->fresh(),
            $created ? Response::HTTP_CREATED : Response::HTTP_OK,
        );
    }

    public function destroy(Request $request, Game $game, ManualLibrary $manual): Response
    {
        abort_unless($manual->remove($request->user(), $game), Response::HTTP_NOT_FOUND);

        return response()->noContent();
    }
}
