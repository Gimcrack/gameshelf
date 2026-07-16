<?php

namespace App\Jobs;

use App\Jobs\Concerns\RateLimitedIgdbSync;
use App\Models\PlatformConnection;
use App\Services\Igdb\GameMatcher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * T32/V39: one connection's provisional-game matching, fanned out from
 * SyncLibraryIgdb so the orchestrator never does IGDB work itself.
 * GameMatcher's V26 tolerance already isolates per-game failures inside
 * the connection; V4's forever-cache keeps repeat runs cheap.
 */
class MatchConnectionIgdb implements ShouldQueue
{
    use Queueable;
    use RateLimitedIgdbSync;

    public function __construct(public readonly int $connectionId)
    {
    }

    public function handle(GameMatcher $matcher): void
    {
        $connection = PlatformConnection::find($this->connectionId);

        if ($connection === null) {
            return;
        }

        $matcher->matchConnection($connection);
    }
}
