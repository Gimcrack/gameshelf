<?php

namespace App\Http\Controllers\Library;

use App\Enums\GameStatus;
use App\Http\Controllers\Controller;
use App\Services\Library\LibraryQuery;
use App\Services\Library\SystemCollections;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

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

        return [...$custom->filters, ...$validated];
    }
}
