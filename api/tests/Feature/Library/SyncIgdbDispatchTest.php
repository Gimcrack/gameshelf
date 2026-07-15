<?php

namespace Tests\Feature\Library;

use App\Jobs\SyncLibraryIgdb;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class SyncIgdbDispatchTest extends TestCase
{
    use RefreshDatabase;

    /**
     * T31/V38: bulk sync runs async via queue — endpoint dispatches and
     * returns 202, never inline.
     */
    public function test_sync_igdb_dispatches_job_and_returns_202(): void
    {
        Queue::fake();
        $user = User::factory()->create();

        $this->withToken($user->createToken('t')->plainTextToken)
            ->postJson('/api/library/sync-igdb')
            ->assertAccepted();

        Queue::assertPushed(SyncLibraryIgdb::class, fn ($job) => $job->userId === $user->id);
    }

    /**
     * §C/V38: throttled to one per 5 minutes per user.
     */
    public function test_sync_igdb_throttled_within_five_minutes(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $token = $user->createToken('t')->plainTextToken;

        $this->withToken($token)->postJson('/api/library/sync-igdb')->assertAccepted();
        $this->withToken($token)->postJson('/api/library/sync-igdb')->assertTooManyRequests();

        Queue::assertPushed(SyncLibraryIgdb::class, 1);
    }

    /**
     * Throttle is per-user, not global — another user is unaffected.
     *
     * Auth::forgetGuards() is required here: Sanctum's RequestGuard caches
     * the resolved user on first use within a test method, so a second
     * withToken() for a different user wouldn't actually re-resolve
     * without it — a test-harness quirk, not app behavior (a real second
     * HTTP request has no such cache).
     */
    public function test_sync_igdb_throttle_is_per_user(): void
    {
        Queue::fake();
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        $this->withToken($userA->createToken('t')->plainTextToken)
            ->postJson('/api/library/sync-igdb')
            ->assertAccepted();

        Auth::forgetGuards();

        $this->withToken($userB->createToken('t')->plainTextToken)
            ->postJson('/api/library/sync-igdb')
            ->assertAccepted();

        Queue::assertPushed(SyncLibraryIgdb::class, 2);
    }

    public function test_sync_igdb_requires_auth(): void
    {
        $this->withHeaders(['Authorization' => ''])
            ->postJson('/api/library/sync-igdb')
            ->assertUnauthorized();
    }
}
