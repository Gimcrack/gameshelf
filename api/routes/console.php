<?php

use App\Enums\ConnectionStatus;
use App\Jobs\SyncConnection;
use App\Models\PlatformConnection;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// V5: automatic polling capped at once per day per connection.
Schedule::call(function () {
    PlatformConnection::query()
        ->whereNot('status', ConnectionStatus::Disconnected)
        ->pluck('id')
        ->each(fn (int $id) => SyncConnection::dispatch($id));
})->daily()->name('daily-connection-sync');
