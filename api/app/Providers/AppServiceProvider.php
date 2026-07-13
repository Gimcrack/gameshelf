<?php

namespace App\Providers;

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
    }
}
