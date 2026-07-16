<?php

namespace App\Http\Controllers\Connections;

use App\Enums\ConnectionStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Connections\ResolveSteamIdentityRequest;
use App\Http\Requests\Connections\StoreConnectionRequest;
use App\Jobs\SyncConnection;
use App\Models\PlatformConnection;
use App\Services\Gog\GogClient;
use App\Services\Steam\SteamClient;
use App\Services\Xbox\XboxClient;
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
        // V19: the synthetic manual connection is plumbing, not a service.
        return response()->json(
            $request->user()->platformConnections()->where('platform', '!=', 'manual')->get(),
        );
    }

    public function store(StoreConnectionRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $connection = match ($validated['platform']) {
            'steam' => $this->connectSteam($request, $validated),
            'gog' => $this->connectGog($request, $validated),
            'xbox' => $this->connectXbox($request, $validated),
        };

        SyncConnection::dispatch($connection->id);

        return response()->json($connection, Response::HTTP_CREATED);
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function connectSteam(StoreConnectionRequest $request, array $validated): PlatformConnection
    {
        $steamId = $this->resolveSteamId($validated);

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
     * V25: identity preview — resolves and shows persona_name/avatar_url so
     * the caller can confirm before a connection row ever exists. Basic
     * identity is public even for privacy-locked profiles (V15 is about the
     * game library, not identity), so this works regardless of later sync
     * privacy state.
     */
    public function resolveSteam(ResolveSteamIdentityRequest $request): JsonResponse
    {
        $steamId = $this->resolveSteamId($request->validated());

        if ($steamId === null) {
            throw ValidationException::withMessages([
                'vanity_url' => ['No Steam account matches that vanity URL.'],
            ]);
        }

        $identity = app(SteamClient::class)->playerSummary($steamId);

        abort_if($identity === null, Response::HTTP_NOT_FOUND);

        return response()->json($identity);
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function resolveSteamId(array $validated): ?string
    {
        return $validated['steam_id']
            ?? app(SteamClient::class)->resolveVanityUrl($validated['vanity_url']);
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
     * I.xbox/V60: exchange code → MS tokens → XBL/XSTS hop for the xuid,
     * confirming the whole chain works before the connection row exists.
     *
     * @param  array<string, mixed>  $validated
     */
    private function connectXbox(StoreConnectionRequest $request, array $validated): PlatformConnection
    {
        $tokens = app(XboxClient::class)->exchangeCode($validated['code'], $validated['redirect_uri']);

        if ($tokens === null) {
            throw ValidationException::withMessages([
                'code' => ['Microsoft rejected that login code. Log in again and retry.'],
            ]);
        }

        $credentials = app(XboxClient::class)->exchangeForXsts($tokens['access_token']);

        if ($credentials === null) {
            throw ValidationException::withMessages([
                'code' => ['Could not authorize with Xbox Live. Log in again and retry.'],
            ]);
        }

        return $request->user()->platformConnections()->create([
            'platform' => 'xbox',
            'external_account_id' => $credentials['xuid'],
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

        $throttleKey = "sync-now-2:{$connection->id}";

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
