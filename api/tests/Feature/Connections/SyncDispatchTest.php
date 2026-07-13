<?php

namespace Tests\Feature\Connections;

use App\Jobs\SyncConnection;
use App\Models\PlatformConnection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class SyncDispatchTest extends TestCase
{
    use RefreshDatabase;

    /**
     * V8: sync runs async via queue — endpoint dispatches and returns 202.
     */
    public function test_sync_now_dispatches_job_and_returns_202(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $connection = PlatformConnection::factory()->create(['user_id' => $user->id]);

        $this->withToken($user->createToken('t')->plainTextToken)
            ->postJson("/api/connections/{$connection->id}/sync")
            ->assertAccepted();

        Queue::assertPushed(SyncConnection::class, fn ($job) => $job->connectionId === $connection->id);
    }

    /**
     * §C: sync-now throttled to one per 5 minutes per connection.
     */
    public function test_sync_now_throttled_within_five_minutes(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $connection = PlatformConnection::factory()->create(['user_id' => $user->id]);
        $token = $user->createToken('t')->plainTextToken;

        $this->withToken($token)
            ->postJson("/api/connections/{$connection->id}/sync")
            ->assertAccepted();

        $this->withToken($token)
            ->postJson("/api/connections/{$connection->id}/sync")
            ->assertTooManyRequests();

        Queue::assertPushed(SyncConnection::class, 1);
    }

    public function test_cannot_sync_other_users_connection(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $other = PlatformConnection::factory()->create();

        $this->withToken($user->createToken('t')->plainTextToken)
            ->postJson("/api/connections/{$other->id}/sync")
            ->assertNotFound();

        Queue::assertNothingPushed();
    }
}
