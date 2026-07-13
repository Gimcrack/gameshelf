<?php

namespace App\Http\Controllers\Connections;

use App\Enums\ConnectionStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Connections\StoreConnectionRequest;
use App\Jobs\SyncConnection;
use App\Models\PlatformConnection;
use App\Services\Steam\SteamClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class ConnectionController extends Controller
{
    /**
     * V9: every connection exposes its sync status.
     */
    public function index(Request $request): JsonResponse
    {
        return response()->json($request->user()->platformConnections);
    }

    public function store(StoreConnectionRequest $request, SteamClient $steam): JsonResponse
    {
        $validated = $request->validated();
        $steamId = $validated['steam_id'] ?? $steam->resolveVanityUrl($validated['vanity_url']);

        if ($steamId === null) {
            throw ValidationException::withMessages([
                'vanity_url' => ['No Steam account matches that vanity URL.'],
            ]);
        }

        $connection = $request->user()->platformConnections()->create([
            'platform' => $validated['platform'],
            'external_account_id' => $steamId,
            'status' => ConnectionStatus::Pending,
        ]);

        SyncConnection::dispatch($connection->id);

        return response()->json($connection, Response::HTTP_CREATED);
    }

    /**
     * V8: dispatch and return 202 — sync never runs in the request cycle.
     * §C: manual sync throttled to one per 5 minutes per connection.
     */
    public function sync(Request $request, PlatformConnection $connection): JsonResponse
    {
        abort_unless($connection->user_id === $request->user()->id, Response::HTTP_NOT_FOUND);

        $throttleKey = "sync-now:{$connection->id}";

        if (! RateLimiter::attempt($throttleKey, 1, fn () => null, 300)) {
            return response()->json([
                'message' => 'Sync already requested recently. Try again in a few minutes.',
            ], Response::HTTP_TOO_MANY_REQUESTS);
        }

        SyncConnection::dispatch($connection->id);

        return response()->json([
            'message' => 'Sync queued.',
        ], Response::HTTP_ACCEPTED);
    }
}
