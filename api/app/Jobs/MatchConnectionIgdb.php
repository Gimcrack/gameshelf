<?php

namespace App\Jobs;

use App\Models\PlatformConnection;
use App\Services\Igdb\GameMatcher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\RateLimited;

/**
 * T32/V39: one connection's provisional-game matching, fanned out from
 * SyncLibraryIgdb so the orchestrator never does IGDB work itself.
 * GameMatcher's V26 tolerance already isolates per-game failures inside
 * the connection; V4's forever-cache keeps repeat runs cheap.
 */
class MatchConnectionIgdb implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly int $connectionId)
    {
    }

    /**
     * V39: rate limit enforced at the queue layer — an over-budget job is
     * released back with a delay instead of sleep-blocking a worker.
     *
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [new RateLimited('igdb-sync')];
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
