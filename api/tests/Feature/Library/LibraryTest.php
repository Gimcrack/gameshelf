<?php

namespace Tests\Feature\Library;

use App\Models\Game;
use App\Models\OwnedGame;
use App\Models\PlatformConnection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LibraryTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->withToken($this->user->createToken('t')->plainTextToken);
    }

    private function connection(string $platform, string $status = 'ok'): PlatformConnection
    {
        return PlatformConnection::factory()->create([
            'user_id' => $this->user->id,
            'platform' => $platform,
            'status' => $status,
        ]);
    }

    private function own(
        PlatformConnection $connection,
        Game $game,
        ?int $playtime = null,
        ?string $lastPlayed = null,
        string $added = '2026-01-01 00:00:00',
    ): OwnedGame {
        return OwnedGame::create([
            'user_id' => $connection->user_id,
            'platform_connection_id' => $connection->id,
            'game_id' => $game->id,
            'platform_game_id' => (string) fake()->unique()->numberBetween(1, 999999),
            'playtime_minutes' => $playtime,
            'last_played_at' => $lastPlayed,
            'added_at' => $added,
        ]);
    }

    private function game(string $title, array $attrs = []): Game
    {
        return Game::create(['title' => $title, ...$attrs]);
    }

    /**
     * V1: same game owned on two platforms is one library entry with both
     * platforms listed — dedupe happens at query time.
     */
    public function test_deduplicates_multi_platform_games(): void
    {
        $game = $this->game('Portal 2');
        $this->own($this->connection('steam'), $game, 1200);
        $this->own($this->connection('gog'), $game, 60);

        $response = $this->getJson('/api/library')->assertOk();

        $this->assertCount(1, $response->json());
        $entry = $response->json()[0];
        $platforms = array_column($entry['platforms'], 'platform');
        sort($platforms);
        $this->assertSame(['gog', 'steam'], $platforms);
        $this->assertSame(1260, $entry['total_playtime_minutes']);
    }

    /**
     * V12: null playtime is unknown, not zero — excluded from unplayed.
     */
    public function test_unplayed_filter_excludes_unknown_playtime(): void
    {
        $this->own($this->connection('steam'), $this->game('Played'), 500);
        $this->own($this->connection('gog'), $this->game('Unknown'), null);
        $this->own($this->connection('steam'), $this->game('Unplayed'), 0);

        $response = $this->getJson('/api/library?unplayed=1')->assertOk();

        $titles = array_column($response->json(), 'title');
        $this->assertSame(['Unplayed'], $titles);
    }

    public function test_unknown_playtime_stays_null_in_payload(): void
    {
        $this->own($this->connection('gog'), $this->game('Unknown'), null);

        $entry = $this->getJson('/api/library')->json()[0];

        $this->assertNull($entry['total_playtime_minutes']);
        $this->assertNull($entry['platforms'][0]['playtime_minutes']);
    }

    /**
     * V13: games from a disconnected connection stay in the library and
     * carry the disconnected status for UI badging.
     */
    public function test_disconnected_games_remain_with_status(): void
    {
        $this->own($this->connection('steam', 'disconnected'), $this->game('Portal 2'), 100);

        $response = $this->getJson('/api/library')->assertOk();

        $this->assertCount(1, $response->json());
        $this->assertSame('disconnected', $response->json()[0]['platforms'][0]['connection_status']);
    }

    public function test_filters_by_platform(): void
    {
        $this->own($this->connection('steam'), $this->game('Steam Game'), 10);
        $this->own($this->connection('gog'), $this->game('GOG Game'), 10);

        $titles = array_column($this->getJson('/api/library?platform=gog')->json(), 'title');

        $this->assertSame(['GOG Game'], $titles);
    }

    public function test_filters_by_genre(): void
    {
        $steam = $this->connection('steam');
        $this->own($steam, $this->game('Puzzler', ['genres' => ['Puzzle']]), 10);
        $this->own($steam, $this->game('Shooter', ['genres' => ['Shooter']]), 10);

        $titles = array_column($this->getJson('/api/library?genre=Puzzle')->json(), 'title');

        $this->assertSame(['Puzzler'], $titles);
    }

    public function test_filters_by_theme(): void
    {
        $steam = $this->connection('steam');
        $this->own($steam, $this->game('Dark One', ['themes' => ['Horror']]), 10);
        $this->own($steam, $this->game('Light One', ['themes' => ['Comedy']]), 10);

        $titles = array_column($this->getJson('/api/library?theme=Horror')->json(), 'title');

        $this->assertSame(['Dark One'], $titles);
    }

    public function test_filters_by_keyword(): void
    {
        $steam = $this->connection('steam');
        $this->own($steam, $this->game('Pixel One', ['keywords' => ['pixel-art']]), 10);
        $this->own($steam, $this->game('Realistic One', ['keywords' => ['photorealistic']]), 10);

        $titles = array_column($this->getJson('/api/library?keyword=pixel-art')->json(), 'title');

        $this->assertSame(['Pixel One'], $titles);
    }

    public function test_filters_by_game_mode(): void
    {
        $steam = $this->connection('steam');
        $this->own($steam, $this->game('Solo One', ['game_modes' => ['Single player']]), 10);
        $this->own($steam, $this->game('Party One', ['game_modes' => ['Multiplayer']]), 10);

        $titles = array_column($this->getJson('/api/library?game_mode=Multiplayer')->json(), 'title');

        $this->assertSame(['Party One'], $titles);
    }

    public function test_filters_by_playtime_range(): void
    {
        $steam = $this->connection('steam');
        $this->own($steam, $this->game('Short'), 30);
        $this->own($steam, $this->game('Medium'), 300);
        $this->own($steam, $this->game('Long'), 3000);
        $this->own($steam, $this->game('Unknown'), null);

        $titles = array_column(
            $this->getJson('/api/library?playtime_min=100&playtime_max=1000')->json(),
            'title',
        );

        $this->assertSame(['Medium'], $titles);
    }

    public function test_sorts_alpha_by_default(): void
    {
        $steam = $this->connection('steam');
        $this->own($steam, $this->game('Zelda-like'), 10);
        $this->own($steam, $this->game('Axiom Verge'), 10);

        $titles = array_column($this->getJson('/api/library')->json(), 'title');

        $this->assertSame(['Axiom Verge', 'Zelda-like'], $titles);
    }

    public function test_sorts_by_playtime_desc_nulls_last(): void
    {
        $steam = $this->connection('steam');
        $this->own($steam, $this->game('Short'), 30);
        $this->own($steam, $this->game('Long'), 3000);
        $this->own($steam, $this->game('Unknown'), null);

        $titles = array_column(
            $this->getJson('/api/library?sort=playtime&order=desc')->json(),
            'title',
        );

        $this->assertSame(['Long', 'Short', 'Unknown'], $titles);
    }

    public function test_sorts_by_last_played_desc_nulls_last(): void
    {
        $steam = $this->connection('steam');
        $this->own($steam, $this->game('Recent'), 10, '2026-07-01 10:00:00');
        $this->own($steam, $this->game('Older'), 10, '2026-01-01 10:00:00');
        $this->own($steam, $this->game('Never'), 10, null);

        $titles = array_column(
            $this->getJson('/api/library?sort=last_played&order=desc')->json(),
            'title',
        );

        $this->assertSame(['Recent', 'Older', 'Never'], $titles);
    }

    public function test_sorts_by_added_date(): void
    {
        $steam = $this->connection('steam');
        $this->own($steam, $this->game('Newest'), 10, null, '2026-06-01 00:00:00');
        $this->own($steam, $this->game('Oldest'), 10, null, '2025-01-01 00:00:00');

        $titles = array_column(
            $this->getJson('/api/library?sort=added&order=desc')->json(),
            'title',
        );

        $this->assertSame(['Newest', 'Oldest'], $titles);
    }

    public function test_rejects_invalid_sort(): void
    {
        $this->getJson('/api/library?sort=bogus')->assertUnprocessable();
    }

    public function test_does_not_leak_other_users_games(): void
    {
        $other = PlatformConnection::factory()->create();
        $this->own($other, $this->game('Not Mine'), 10);

        $this->assertCount(0, $this->getJson('/api/library')->json());
    }

    public function test_requires_auth(): void
    {
        $this->withHeaders(['Authorization' => ''])
            ->getJson('/api/library')
            ->assertUnauthorized();
    }

    /**
     * I.api T24: show returns the same entry shape as the list, for one game.
     */
    public function test_show_returns_single_entry(): void
    {
        $game = $this->game('Portal 2', ['themes' => ['Sci-fi']]);
        $this->own($this->connection('steam'), $game, 1200);
        $this->own($this->connection('gog'), $game, 60);

        $response = $this->getJson("/api/library/{$game->id}")->assertOk();

        $this->assertSame('Portal 2', $response->json('title'));
        $this->assertSame(1260, $response->json('total_playtime_minutes'));
        $platforms = array_column($response->json('platforms'), 'platform');
        sort($platforms);
        $this->assertSame(['gog', 'steam'], $platforms);
    }

    public function test_show_404_when_not_owned(): void
    {
        $game = $this->game('Not Mine');

        $this->getJson("/api/library/{$game->id}")->assertNotFound();
    }

    public function test_show_404_for_other_users_game(): void
    {
        $other = PlatformConnection::factory()->create();
        $game = $this->game('Theirs');
        $this->own($other, $game, 10);

        $this->getJson("/api/library/{$game->id}")->assertNotFound();
    }

    public function test_show_requires_auth(): void
    {
        $game = $this->game('Portal 2');
        $this->own($this->connection('steam'), $game, 10);

        $this->withHeaders(['Authorization' => ''])
            ->getJson("/api/library/{$game->id}")
            ->assertUnauthorized();
    }
}
