<?php

namespace Tests\Feature\Library;

use App\Models\Game;
use App\Models\OwnedGame;
use App\Models\PlatformConnection;
use App\Models\User;
use App\Models\UserGameMeta;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SmartCollectionsTest extends TestCase
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

    private function own(string $title, array $game = [], array $owned = []): Game
    {
        $model = Game::create(['title' => $title, ...$game]);
        OwnedGame::create([
            'user_id' => $this->user->id,
            'platform_connection_id' => $this->connection->id,
            'game_id' => $model->id,
            'platform_game_id' => (string) fake()->unique()->numberBetween(1, 999999),
            'playtime_minutes' => null,
            'added_at' => '2026-01-01 00:00:00',
            ...$owned,
        ]);

        return $model;
    }

    private function meta(Game $game, array $attrs): void
    {
        UserGameMeta::create([
            'user_id' => $this->user->id,
            'game_id' => $game->id,
            ...$attrs,
        ]);
    }

    /** @return list<string> */
    private function titles(string $query): array
    {
        return array_column($this->getJson("/api/library?{$query}")->assertOk()->json(), 'title');
    }

    /**
     * V12: unplayed = known-zero playtime or user-declared unplayed; null
     * playtime alone is unknown and excluded.
     */
    public function test_unplayed_collection_v12_semantics(): void
    {
        $this->own('Zero Playtime', [], ['playtime_minutes' => 0]);
        $this->own('Unknown Playtime');
        $declared = $this->own('Declared Unplayed');
        $this->meta($declared, ['status' => 'unplayed']);
        $played = $this->own('Played', [], ['playtime_minutes' => 500]);

        $this->assertSame(
            ['Declared Unplayed', 'Zero Playtime'],
            $this->titles('collection=unplayed'),
        );
    }

    /**
     * Abandoned: played, untouched 6+ months, not finished (§I.api).
     */
    public function test_abandoned_collection(): void
    {
        $this->own('Old Unfinished', [], [
            'playtime_minutes' => 300,
            'last_played_at' => now()->subMonths(7),
        ]);
        $finished = $this->own('Old Finished', [], [
            'playtime_minutes' => 3000,
            'last_played_at' => now()->subMonths(8),
        ]);
        $this->meta($finished, ['status' => 'finished']);
        $this->own('Recently Played', [], [
            'playtime_minutes' => 300,
            'last_played_at' => now()->subWeek(),
        ]);
        $this->own('Never Played', [], ['playtime_minutes' => 0]);

        $this->assertSame(['Old Unfinished'], $this->titles('collection=abandoned'));
    }

    /**
     * §C: quick wins need IGDB time-to-beat under 5 hours; games without
     * the data are excluded, never guessed.
     */
    public function test_quick_wins_collection_conditional_on_time_to_beat(): void
    {
        $this->own('Short Game', ['time_to_beat_minutes' => 240]);
        $this->own('Long Game', ['time_to_beat_minutes' => 3600]);
        $this->own('No Data Game');

        $this->assertSame(['Short Game'], $this->titles('collection=quick_wins'));
    }

    public function test_custom_collection_applies_saved_filters(): void
    {
        $rpg = $this->own('Owned RPG', ['genres' => ['RPG']]);
        $this->own('Owned Puzzle', ['genres' => ['Puzzle']]);
        $this->meta($rpg, ['status' => 'playing']);

        $id = $this->postJson('/api/collections', [
            'name' => 'Current RPGs',
            'filters' => ['genre' => 'RPG', 'status' => 'playing'],
        ])->assertCreated()->json('id');

        $this->assertSame(['Owned RPG'], $this->titles("collection={$id}"));
    }

    public function test_status_and_tag_filters(): void
    {
        $tagged = $this->own('Tagged Game');
        $this->meta($tagged, ['status' => 'playing', 'tags' => ['co-op', 'favorite']]);
        $other = $this->own('Other Game');
        $this->meta($other, ['status' => 'finished', 'tags' => ['solo']]);
        $this->own('Bare Game');

        $this->assertSame(['Tagged Game'], $this->titles('status=playing'));
        $this->assertSame(['Tagged Game'], $this->titles('tags=co-op'));
        $this->assertSame(['Other Game'], $this->titles('tags=solo,finished-tag'));
        // Games without meta default to unplayed status.
        $this->assertSame(['Bare Game'], $this->titles('status=unplayed'));
    }

    public function test_unknown_collection_rejected(): void
    {
        $this->getJson('/api/library?collection=nonsense')->assertUnprocessable();
    }
}
