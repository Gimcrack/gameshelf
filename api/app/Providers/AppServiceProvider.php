<?php

namespace App\Providers;

use App\Services\Gog\GogClient;
use App\Services\Igdb\IgdbClient;
use App\Services\Igdb\TwitchAuth;
use App\Services\Steam\SteamClient;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(SteamClient::class, function () {
            return new SteamClient((string) config('services.steam.key'));
        });

        $this->app->bind(GogClient::class, function () {
            return new GogClient(
                (string) config('services.gog.client_id'),
                (string) config('services.gog.client_secret'),
            );
        });

        $this->app->bind(TwitchAuth::class, function () {
            return new TwitchAuth(
                (string) config('services.twitch.client_id'),
                (string) config('services.twitch.client_secret'),
            );
        });

        $this->app->bind(IgdbClient::class, function () {
            return new IgdbClient(
                (string) config('services.twitch.client_id'),
                app(TwitchAuth::class),
            );
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('auth', function (Request $request) {
            return Limit::perMinute(10)->by($request->ip());
        });

        // V39: gates IGDB fan-out job starts. 2 jobs/s × ~2 IGDB calls per
        // job stays inside the shared 4 req/s IGDB budget (§C discovery,
        // V4); IgdbClient::throttle remains the per-request final guard.
        RateLimiter::for('igdb-sync', function () {
            return Limit::perSecond(2);
        });
    }
}
