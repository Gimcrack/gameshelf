<?php

namespace App\Http\Controllers\Library;

use App\Http\Controllers\Controller;
use App\Models\Collection;
use App\Models\Game;
use App\Services\Library\SystemCollections;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

class CollectionController extends Controller
{
    /**
     * Filter keys a saved preset may contain — the /api/library filter
     * vocabulary, minus `collection` itself (no recursive presets).
     */
    private const ALLOWED_FILTER_KEYS = [
        'sort', 'order', 'platform', 'genre', 'theme', 'keyword', 'game_mode',
        'status', 'tags', 'unplayed', 'playtime_min', 'playtime_max',
    ];

    public function index(Request $request): JsonResponse
    {
        return response()->json([
            'system' => SystemCollections::all(),
            'custom' => $request->user()->collections()->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'type' => ['sometimes', Rule::in(['filter', 'manual'])],
            'filters' => ['required_unless:type,manual', 'array'],
            'filters.*' => ['required'],
        ]);

        $type = $validated['type'] ?? 'filter';

        // V29: manual ⊥ has filters — enforced regardless of client input.
        if ($type === 'manual') {
            $collection = $request->user()->collections()->create([
                'name' => $validated['name'],
                'type' => 'manual',
                'filters' => null,
            ]);

            return response()->json($collection, Response::HTTP_CREATED);
        }

        $unknownKeys = array_diff(array_keys($validated['filters']), self::ALLOWED_FILTER_KEYS);

        if ($unknownKeys !== []) {
            return response()->json([
                'message' => 'Unknown filter keys: '.implode(', ', $unknownKeys),
                'errors' => ['filters' => ['Unknown filter keys: '.implode(', ', $unknownKeys)]],
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $collection = $request->user()->collections()->create([
            'name' => $validated['name'],
            'type' => 'filter',
            'filters' => $validated['filters'],
        ]);

        return response()->json($collection, Response::HTTP_CREATED);
    }

    /**
     * V29: manual collections only — 422 otherwise. Idempotent (mirrors
     * ManualEntryController/WishlistController duplicate-add precedent).
     */
    public function addGame(Request $request, Collection $collection): JsonResponse
    {
        abort_unless($collection->user_id === $request->user()->id, Response::HTTP_NOT_FOUND);
        abort_unless($collection->type === 'manual', Response::HTTP_UNPROCESSABLE_ENTITY,
            'Only manual collections accept game membership.');

        $validated = $request->validate([
            'game_id' => ['required', 'integer', 'exists:games,id'],
        ]);

        $alreadyMember = $collection->games()->where('games.id', $validated['game_id'])->exists();

        if ($alreadyMember) {
            return response()->json($collection->fresh('games'));
        }

        $collection->games()->attach($validated['game_id'], ['added_at' => now()]);

        return response()->json($collection->fresh('games'), Response::HTTP_CREATED);
    }

    /**
     * V29: manual collections only. Idempotent — removing a non-member is a
     * no-op 200, not an error.
     */
    public function removeGame(Request $request, Collection $collection, Game $game): JsonResponse
    {
        abort_unless($collection->user_id === $request->user()->id, Response::HTTP_NOT_FOUND);
        abort_unless($collection->type === 'manual', Response::HTTP_UNPROCESSABLE_ENTITY,
            'Only manual collections accept game membership.');

        $collection->games()->detach($game->id);

        return response()->json($collection->fresh('games'));
    }
}
