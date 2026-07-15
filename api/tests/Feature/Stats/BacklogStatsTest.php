<?php

namespace Tests\Feature\Stats;

use App\Models\Game;
use App\Models\OwnedGame;
use App\Models\PlatformConnection;
use App\Models\PlaytimeSnapshot;
use App\Models\User;
use App\Models\UserGameMeta;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BacklogStatsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private PlatformConnection $connection;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->withToken($this->user->createToken('t')->plainTextToken);
        $this->connection = PlatformConnection::factory()->create([
            'user_id' => $this->user->id,
            'platform' => 'steam',
            'status' => 'ok',
        ]);
    }

    private function own(string $title, array $game = [], array $owned = []): OwnedGame
    {
        $model = Game::create(['title' => $title, ...$game]);

        return OwnedGame::create([
            'user_id' => $this->user->id,
            'platform_connection_id' => $this->connection->id,
            'game_id' => $model->id,
            'platform_game_id' => (string) fake()->unique()->numberBetween(1, 999999),
            'playtime_minutes' => null,
            'added_at' => '2026-01-01 00:00:00',
            ...$owned,
        ]);
    }

    private function snapshot(OwnedGame $owned, int $minutes, string $capturedAt): void
    {
        PlaytimeSnapshot::create([
            'owned_game_id' => $owned->id,
            'playtime_minutes' => $minutes,
            'captured_at' => $capturedAt,
        ]);
    }

    /**
     * V12: unplayed count is known-zero or declared unplayed; null playtime
     * alone never counts.
     */
    public function test_unplayed_count_follows_v12(): void
    {
        $this->own('Zero', [], ['playtime_minutes' => 0]);
        $this->own('Unknown');
        $declared = $this->own('Declared', [], ['playtime_minutes' => null]);
        UserGameMeta::create([
            'user_id' => $this->user->id,
            'game_id' => $declared->game_id,
            'status' => 'unplayed',
        ]);
        $this->own('Played', [], ['playtime_minutes' => 600]);

        $this->getJson('/api/stats/backlog')
            ->assertOk()
            ->assertJsonPath('unplayed_count', 2);
    }

    /**
     * §C: est_hours only counts unplayed games with time-to-beat data.
     */
    public function test_est_hours_gated_on_time_to_beat(): void
    {
        $this->own('Short', ['time_to_beat_minutes' => 240], ['playtime_minutes' => 0]);
        $this->own('Long', ['time_to_beat_minutes' => 6000], ['playtime_minutes' => 0]);
        $this->own('No Data', [], ['playtime_minutes' => 0]);
        // Played games never contribute, even with data.
        $this->own('Done', ['time_to_beat_minutes' => 1200], ['playtime_minutes' => 900]);

        $this->getJson('/api/stats/backlog')
            ->assertOk()
            ->assertJsonPath('unplayed_count', 3)
            ->assertJsonPath('est_hours', 104); // (240 + 6000) / 60
    }

    /**
     * V16: burn-down pace comes from playtime snapshot deltas.
     */
    public function test_burndown_pace_from_snapshots(): void
    {
        $backlog = $this->own('Backlog Game', ['time_to_beat_minutes' => 31200], ['playtime_minutes' => 0]);
        $active = $this->own('Active Game', [], ['playtime_minutes' => 1440]);

        // 840 minutes played across the 4-week window = 14 hours → 3.5 h/wk.
        $this->snapshot($active, 600, now()->subWeeks(4)->addDay()->toDateTimeString());
        $this->snapshot($active, 1440, now()->toDateTimeString());

        $response = $this->getJson('/api/stats/backlog')->assertOk();

        $this->assertSame(3.5, $response->json('burndown.avg_hours_per_week'));
        // est 520 hours at 3.5 h/wk = 148.57 weeks ≈ 2.9 years.
        $this->assertSame(2.9, $response->json('burndown.est_years_to_clear'));
    }

    public function test_burndown_null_without_recent_play(): void
    {
        $this->own('Backlog Game', ['time_to_beat_minutes' => 300], ['playtime_minutes' => 0]);

        $response = $this->getJson('/api/stats/backlog')->assertOk();

        // JSON decodes 0.0 as int 0 — loose equality on purpose.
        $this->assertEquals(0, $response->json('burndown.avg_hours_per_week'));
        $this->assertNull($response->json('burndown.est_years_to_clear'));
    }

    public function test_snapshots_outside_window_ignored(): void
    {
        $active = $this->own('Old Grind', [], ['playtime_minutes' => 9000]);
        $this->snapshot($active, 0, now()->subWeeks(20)->toDateTimeString());
        $this->snapshot($active, 9000, now()->subWeeks(10)->toDateTimeString());

        $response = $this->getJson('/api/stats/backlog')->assertOk();

        // JSON decodes 0.0 as int 0 — loose equality on purpose.
        $this->assertEquals(0, $response->json('burndown.avg_hours_per_week'));
    }

    /**
     * V28: hidden games excluded from unplayed_count/est_hours and pace.
     */
    public function test_hidden_games_excluded_from_backlog_stats(): void
    {
        $this->own('Visible Unplayed', ['time_to_beat_minutes' => 300], ['playtime_minutes' => 0]);
        $hidden = $this->own('Hidden Unplayed', ['time_to_beat_minutes' => 600], ['playtime_minutes' => 0]);
        UserGameMeta::create([
            'user_id' => $this->user->id,
            'game_id' => $hidden->game_id,
            'hidden' => true,
        ]);

        $hiddenActive = $this->own('Hidden Active', [], ['playtime_minutes' => 1440]);
        UserGameMeta::create([
            'user_id' => $this->user->id,
            'game_id' => $hiddenActive->game_id,
            'hidden' => true,
        ]);
        $this->snapshot($hiddenActive, 0, now()->subWeeks(4)->addDay()->toDateTimeString());
        $this->snapshot($hiddenActive, 1440, now()->toDateTimeString());

        $response = $this->getJson('/api/stats/backlog')->assertOk();

        $this->assertSame(1, $response->json('unplayed_count'));
        $this->assertSame(5, $response->json('est_hours'));
        // Hidden active game's snapshot delta contributes nothing.
        $this->assertEquals(0, $response->json('burndown.avg_hours_per_week'));
    }
}
