<?php

namespace App\Http\Controllers\Library;

use App\Enums\GameStatus;
use App\Http\Controllers\Controller;
use App\Models\Game;
use App\Services\Library\LibraryQuery;
use App\Services\Library\SystemCollections;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class LibraryController extends Controller
{
    public function index(Request $request, LibraryQuery $library): JsonResponse
    {
        $validated = $request->validate([
            'sort' => ['sometimes', 'in:alpha,playtime,last_played,added'],
            'order' => ['sometimes', 'in:asc,desc'],
            'platform' => ['sometimes', 'in:steam,gog'],
            'genre' => ['sometimes', 'string', 'max:100'],
            'theme' => ['sometimes', 'string', 'max:100'],
            'keyword' => ['sometimes', 'string', 'max:100'],
            'game_mode' => ['sometimes', 'string', 'max:100'],
            'playtime_min' => ['sometimes', 'integer', 'min:0'],
            'playtime_max' => ['sometimes', 'integer', 'min:0'],
            'unplayed' => ['sometimes', 'boolean'],
            'status' => ['sometimes', Rule::enum(GameStatus::class)],
            'tags' => ['sometimes', 'string', 'max:500'],
            'collection' => ['sometimes', 'string', 'max:100'],
        ]);

        if (isset($validated['collection'])) {
            $validated = $this->resolveCollection($request, $validated);
        }

        return response()->json($library->forUser($request->user(), $validated));
    }

    /**
     * I.api: GET /api/library/:game_id — 404 if the game isn't in the
     * caller's library (not just "doesn't exist").
     */
    public function show(Request $request, Game $game, LibraryQuery $library): JsonResponse
    {
        $entry = $library->forGame($request->user(), $game);

        abort_if($entry === null, Response::HTTP_NOT_FOUND);

        return response()->json($entry);
    }

    /**
     * A collection param is either a system slug (kept for LibraryQuery) or
     * a custom preset id whose saved filters merge in — explicit query
     * params win over the preset.
     *
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function resolveCollection(Request $request, array $validated): array
    {
        $collection = $validated['collection'];

        if (in_array($collection, SystemCollections::slugs(), true)) {
            return $validated;
        }

        $custom = ctype_digit($collection)
            ? $request->user()->collections()->find((int) $collection)
            : null;

        if ($custom === null) {
            throw ValidationException::withMessages([
                'collection' => ['Unknown collection.'],
            ]);
        }

        unset($validated['collection']);

        // V29: manual membership is explicit, not filter evaluation.
        if ($custom->type === 'manual') {
            return ['game_ids' => $custom->games()->pluck('games.id')->all(), ...$validated];
        }

        return [...$custom->filters, ...$validated];
    }
}
