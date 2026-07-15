<?php

namespace App\Http\Controllers\Library;

use App\Enums\DeckStatus;
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
            // T28: multi-select, comma-separated (same convention as `tags`).
            'platform' => ['sometimes', 'string', 'max:100'],
            'genre' => ['sometimes', 'string', 'max:300'],
            'theme' => ['sometimes', 'string', 'max:300'],
            'keyword' => ['sometimes', 'string', 'max:300'],
            'game_mode' => ['sometimes', 'string', 'max:300'],
            'q' => ['sometimes', 'string', 'max:200'],
            'playtime_min' => ['sometimes', 'integer', 'min:0'],
            'playtime_max' => ['sometimes', 'integer', 'min:0'],
            'unplayed' => ['sometimes', 'boolean'],
            'status' => ['sometimes', Rule::enum(GameStatus::class)],
            'tags' => ['sometimes', 'string', 'max:500'],
            'collection' => ['sometimes', 'string', 'max:100'],
            // V28: hidden games excluded by default; this reveals them.
            'include_hidden' => ['sometimes', 'boolean'],
            // T26: matches any owning platform row in the selected set.
            'deck_status' => ['sometimes', 'array'],
            'deck_status.*' => [Rule::enum(DeckStatus::class)],
            // T27
            'esrb' => ['sometimes', 'in:E,E10,T,M,AO,RP'],
            'multiplayer' => ['sometimes', 'boolean'],
            'coop' => ['sometimes', 'boolean'],
            'local_multiplayer' => ['sometimes', 'boolean'],
            'local_coop' => ['sometimes', 'boolean'],
        ]);

        // T27: normalize to real booleans so LibraryQuery's strict equality
        // against nullable game flags behaves correctly.
        foreach (['multiplayer', 'coop', 'local_multiplayer', 'local_coop'] as $flag) {
            if (isset($validated[$flag])) {
                $validated[$flag] = filter_var($validated[$flag], FILTER_VALIDATE_BOOLEAN);
            }
        }

        if (isset($validated['collection'])) {
            $validated = $this->resolveCollection($request, $validated);
        }

        return response()->json($library->forUser($request->user(), $validated));
    }

    /**
     * I.api T28: GET /api/library/facets — distinct filter-able values
     * across the caller's library, feeding the left-sidebar checkboxes.
     */
    public function facets(Request $request, LibraryQuery $library): JsonResponse
    {
        return response()->json($library->facetsForUser($request->user()));
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
