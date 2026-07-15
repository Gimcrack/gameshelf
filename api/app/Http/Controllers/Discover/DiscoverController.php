<?php

namespace App\Http\Controllers\Discover;

use App\Http\Controllers\Controller;
use App\Services\Discover\DiscoverCatalog;
use App\Services\Discover\FranchiseGaps;
use App\Services\Discover\OwnershipOverlay;
use App\Services\Discover\SimilarGames;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DiscoverController extends Controller
{
    public function __construct(
        private readonly DiscoverCatalog $catalog,
        private readonly OwnershipOverlay $overlay,
        private readonly SimilarGames $similarGames,
        private readonly FranchiseGaps $franchiseGaps,
    ) {
    }

    public function search(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'q' => ['required', 'string', 'min:2', 'max:100'],
        ]);

        $hits = $this->catalog->search($validated['q']);

        return response()->json($this->overlay->apply($request->user(), $hits));
    }

    public function browse(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'genre' => ['nullable', 'string', 'max:50'],
            'sort' => ['nullable', 'in:rating,release,popularity'],
            'page' => ['nullable', 'integer', 'min:1', 'max:500'],
        ]);

        $hits = $this->catalog->browse(
            $validated['genre'] ?? null,
            $validated['sort'] ?? 'popularity',
            (int) ($validated['page'] ?? 1),
        );

        return response()->json($this->overlay->apply($request->user(), $hits));
    }

    /**
     * I.api T16: "because you played X" rails from the caller's owned games.
     */
    public function similar(Request $request): JsonResponse
    {
        return response()->json($this->similarGames->railsFor($request->user()));
    }

    /**
     * I.api T18: "complete the series" rails from the caller's owned games.
     */
    public function franchises(Request $request): JsonResponse
    {
        return response()->json($this->franchiseGaps->forUser($request->user()));
    }
}
