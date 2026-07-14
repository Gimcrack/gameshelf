<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class TokenController extends Controller
{
    /**
     * V18: token material never appears here — names and metadata only.
     */
    public function index(Request $request): JsonResponse
    {
        $currentId = $request->user()->currentAccessToken()->id;

        $tokens = $request->user()->tokens()
            ->orderByDesc('created_at')
            ->get()
            ->map(fn ($token) => [
                'id' => $token->id,
                'name' => $token->name,
                'last_used_at' => $token->last_used_at?->toIso8601String(),
                'created_at' => $token->created_at->toIso8601String(),
                'current' => $token->id === $currentId,
            ]);

        return response()->json($tokens);
    }

    /**
     * V18: the plaintext token exists in exactly one response — this one.
     * Sanctum persists only the sha256 hash.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
        ]);

        $token = $request->user()->createToken($validated['name']);

        return response()->json([
            'id' => $token->accessToken->id,
            'name' => $validated['name'],
            'token' => $token->plainTextToken,
        ], Response::HTTP_CREATED);
    }

    public function destroy(Request $request, int $tokenId): Response
    {
        $deleted = $request->user()->tokens()->where('id', $tokenId)->delete();

        abort_unless($deleted > 0, Response::HTTP_NOT_FOUND);

        return response()->noContent();
    }
}
