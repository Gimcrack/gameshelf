<?php

namespace App\Providers;

use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\Horizon;
use Laravel\Horizon\HorizonApplicationServiceProvider;

class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        parent::boot();

        // Horizon::routeSmsNotificationsTo('15556667777');
        // Horizon::routeMailNotificationsTo('example@example.com');
        // Horizon::routeSlackNotificationsTo('slack-webhook-url', '#channel');
    }

    /**
     * V61: this app is Sanctum-only — there's no session-based web login to
     * check an email allowlist against, so this gate can't be the stock
     * pattern. Real access control is HorizonBasicAuth (config/horizon.php
     * `middleware`), which runs first in the middleware stack and fails
     * closed when its credentials are unset — by the time this gate is
     * consulted, the request has already been verified. `return true` is
     * correct here specifically because it's downstream of that check, not
     * a bypass of it.
     */
    protected function gate(): void
    {
        // $user = null is load-bearing: Laravel's Gate only allows a guest
        // (unauthenticated) request through when the callback's first
        // param explicitly allows null — a zero-arg closure gets treated
        // as "not guest-safe" and short-circuits to false before this ever
        // runs. This app has no session-based web user, so every request
        // here is a guest by definition.
        Gate::define('viewHorizon', function ($user = null) {
            return true;
        });
    }
}
