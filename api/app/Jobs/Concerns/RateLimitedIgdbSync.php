<?php

namespace App\Jobs\Concerns;

use DateTimeInterface;
use Illuminate\Queue\Middleware\RateLimited;

/**
 * B16/V52: shared retry policy for the IGDB fan-out jobs (MatchGameIgdb,
 * RefreshGameIgdb, MatchConnectionIgdb). The 'igdb-sync' limiter
 * (Limit::perSecond(2), global) releases every over-budget job back to the
 * queue, and a release counts as an attempt. With the default count ceiling
 * (tries = 1) the 3rd+ job dispatched inside any one-second window is released
 * straight past its ceiling → MaxAttemptsExceededException before handle()
 * ever runs — surfaced by T50's per-game wishlist fan-out flooding the limiter.
 *
 * Fix: bound retries by TIME, not attempt count. retryUntil lets a released
 * job wait out the limiter and drain at 2/sec; maxExceptions still caps a
 * genuinely erroring job so a hard failure can't loop until the deadline.
 */
trait RateLimitedIgdbSync
{
    public int $maxExceptions = 3;

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

    /**
     * V52: time-based retry ceiling. A limiter release counts as an attempt,
     * so a count ceiling would fail flooded jobs before they run; the window
     * is generous enough to drain a single user's union backlog at 2/sec.
     */
    public function retryUntil(): DateTimeInterface
    {
        return now()->addMinutes(15);
    }
}
