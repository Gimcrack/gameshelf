<?php

namespace App\Http\Controllers\Connections;

use App\Enums\ConnectionStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Connections\StoreConnectionRequest;
use App\Jobs\SyncConnection;
use App\Models\PlatformConnection;
use App\Services\Gog\GogClient;
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

    public function store(StoreConnectionRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $connection = match ($validated['platform']) {
            'steam' => $this->connectSteam($request, $validated),
            'gog' => $this->connectGog($request, $validated),
        };

        SyncConnection::dispatch($connection->id);

        return response()->json($connection, Response::HTTP_CREATED);
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function connectSteam(StoreConnectionRequest $request, array $validated): PlatformConnection
    {
        $steamId = $validated['steam_id']
            ?? app(SteamClient::class)->resolveVanityUrl($validated['vanity_url']);

        if ($steamId === null) {
            throw ValidationException::withMessages([
                'vanity_url' => ['No Steam account matches that vanity URL.'],
            ]);
        }

        return $request->user()->platformConnections()->create([
            'platform' => 'steam',
            'external_account_id' => $steamId,
            'status' => ConnectionStatus::Pending,
        ]);
    }

    /**
     * I.gog: exchange the login code server-side; tokens land encrypted via
     * the model casts (V2).
     *
     * @param  array<string, mixed>  $validated
     */
    private function connectGog(StoreConnectionRequest $request, array $validated): PlatformConnection
    {
        $tokens = app(GogClient::class)->exchangeCode($validated['code']);

        if ($tokens === null) {
            throw ValidationException::withMessages([
                'code' => ['GOG rejected that login code. Log in to GOG again and retry.'],
            ]);
        }

        return $request->user()->platformConnections()->create([
            'platform' => 'gog',
            'external_account_id' => $tokens['user_id'],
            'auth_token' => $tokens['access_token'],
            'refresh_token' => $tokens['refresh_token'],
            'token_expires_at' => now()->addSeconds($tokens['expires_in']),
            'status' => ConnectionStatus::Pending,
        ]);
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

    /**
     * V13: disconnect is soft — owned games persist for reconnect; only the
     * status flips. Nothing is deleted.
     */
    public function destroy(Request $request, PlatformConnection $connection): JsonResponse
    {
        abort_unless($connection->user_id === $request->user()->id, Response::HTTP_NOT_FOUND);

        $connection->update(['status' => ConnectionStatus::Disconnected]);

        return response()->json($connection->fresh());
    }
}
