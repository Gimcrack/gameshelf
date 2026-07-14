<?php

namespace App\Http\Controllers\Library;

use App\Http\Controllers\Controller;
use App\Services\Library\SystemCollections;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CollectionController extends Controller
{
    /**
     * Filter keys a saved preset may contain — the /api/library filter
     * vocabulary, minus `collection` itself (no recursive presets).
     */
    private const ALLOWED_FILTER_KEYS = [
        'sort', 'order', 'platform', 'genre', 'status', 'tags',
        'unplayed', 'playtime_min', 'playtime_max',
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
            'filters' => ['required', 'array'],
            'filters.*' => ['required'],
        ]);

        $unknownKeys = array_diff(array_keys($validated['filters']), self::ALLOWED_FILTER_KEYS);

        if ($unknownKeys !== []) {
            return response()->json([
                'message' => 'Unknown filter keys: '.implode(', ', $unknownKeys),
                'errors' => ['filters' => ['Unknown filter keys: '.implode(', ', $unknownKeys)]],
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $collection = $request->user()->collections()->create($validated);

        return response()->json($collection, Response::HTTP_CREATED);
    }
}
