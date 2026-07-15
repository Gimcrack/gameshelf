<?php

namespace Tests\Feature\Igdb;

use App\Jobs\SyncConnection;
use App\Models\Game;
use App\Models\OwnedGame;
use App\Models\PlatformConnection;
use App\Services\Igdb\GameMatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GameMatcherTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  list<array<string, mixed>>  $igdbResults
     */
    private function fakeIgdb(array $igdbResults): void
    {
        Http::fake([
            'id.twitch.tv/oauth2/token*' => Http::response([
                'access_token' => 'twitch-app-token',
                'expires_in' => 3600,
            ]),
            'api.igdb.com/v4/games' => Http::response($igdbResults),
            // §C quick wins: 4.5h normally — matched games get ttb minutes.
            'api.igdb.com/v4/game_time_to_beats' => Http::response([
                ['id' => 9001, 'game_id' => 72, 'normally' => 16200],
            ]),
        ]);
    }

    /**
     * @return array{OwnedGame, Game, PlatformConnection}
     */
    private function provisionalOwnedGame(string $title, string $appId): array
    {
        $connection = PlatformConnection::factory()->create(['status' => 'ok']);
        $game = Game::create(['title' => $title]);
        $ownedGame = OwnedGame::create([
            'user_id' => $connection->user_id,
            'platform_connection_id' => $connection->id,
            'game_id' => $game->id,
            'platform_game_id' => $appId,
            'playtime_minutes' => 100,
            'added_at' => now(),
        ]);

        return [$ownedGame, $game, $connection];
    }

    private function portalIgdbRecord(): array
    {
        return [
            'id' => 72,
            'name' => 'Portal 2',
            'cover' => ['url' => '//images.igdb.com/igdb/image/upload/t_thumb/co1rs4.jpg'],
            'genres' => [['name' => 'Puzzle'], ['name' => 'Platform']],
            'themes' => [['name' => 'Comedy'], ['name' => 'Sci-fi']],
            'keywords' => [['name' => 'physics']],
            'game_modes' => [['name' => 'Single player'], ['name' => 'Co-operative']],
            'first_release_date' => 1303171200,
        ];
    }

    public function test_match_enriches_provisional_game(): void
    {
        $this->fakeIgdb([$this->portalIgdbRecord()]);
        [, $game, $connection] = $this->provisionalOwnedGame('Portal 2', '620');

        app(GameMatcher::class)->matchConnection($connection);

        $game->refresh();
        $this->assertSame(72, $game->igdb_id);
        $this->assertSame('Portal 2', $game->title);
        $this->assertStringContainsString('co1rs4', $game->cover_url);
        $this->assertSame(['Puzzle', 'Platform'], $game->genres);
        $this->assertSame(['Comedy', 'Sci-fi'], $game->themes);
        $this->assertSame(['physics'], $game->keywords);
        $this->assertSame(['Single player', 'Co-operative'], $game->game_modes);
        $this->assertSame('2011-04-19', $game->release_date->toDateString());
    }

    /**
     * V11: IGDB miss keeps the provisional row — games are never dropped.
     */
    public function test_unmatched_game_stays_provisional(): void
    {
        $this->fakeIgdb([]);
        [, $game, $connection] = $this->provisionalOwnedGame('Obscure Indie 9000', '999999');

        app(GameMatcher::class)->matchConnection($connection);

        $game->refresh();
        $this->assertNull($game->igdb_id);
        $this->assertSame('Obscure Indie 9000', $game->title);
        $this->assertDatabaseCount('owned_games', 1);
    }

    /**
     * V4: known platform_game_id never re-queries IGDB.
     * V7: second provisional pointing at the same IGDB game collapses onto
     * the canonical row instead of duplicating it.
     */
    public function test_known_mapping_cached_and_canonical_row_reused(): void
    {
        $this->fakeIgdb([$this->portalIgdbRecord()]);

        [, $gameA, $connectionA] = $this->provisionalOwnedGame('Portal 2', '620');
        app(GameMatcher::class)->matchConnection($connectionA);

        [$ownedB, $gameB, $connectionB] = $this->provisionalOwnedGame('Portal 2', '620');
        app(GameMatcher::class)->matchConnection($connectionB);

        // Exactly one search request ever hit IGDB for appid 620 (V4).
        $igdbCalls = Http::recorded(
            fn ($request) => str_contains($request->url(), 'api.igdb.com/v4/games'),
        );
        $this->assertCount(1, $igdbCalls);

        // One canonical row; second owner repointed; orphan removed (V7).
        $this->assertSame(1, Game::where('igdb_id', 72)->count());
        $this->assertSame($gameA->id, $ownedB->fresh()->game_id);
        $this->assertDatabaseMissing('games', ['id' => $gameB->id]);
    }

    /**
     * V26: one game's search failure never aborts the rest of the batch —
     * previously an exception mid-loop stranded every subsequent game as
     * provisional with no retry path.
     */
    public function test_one_game_failure_does_not_abort_rest_of_batch(): void
    {
        Http::fake([
            'id.twitch.tv/oauth2/token*' => Http::response([
                'access_token' => 'twitch-app-token',
                'expires_in' => 3600,
            ]),
            'api.igdb.com/v4/games' => Http::sequence()
                ->push(null, 500)
                ->push([$this->portalIgdbRecord()]),
            'api.igdb.com/v4/game_time_to_beats' => Http::response([]),
        ]);
        $connection = PlatformConnection::factory()->create(['status' => 'ok']);
        [$failingOwned, $failingGame] = $this->provisionalOwnedGame('Fails To Search', '111');
        $failingOwned->update(['platform_connection_id' => $connection->id, 'user_id' => $connection->user_id]);
        [$succeedsOwned, $succeedsGame] = $this->provisionalOwnedGame('Portal 2', '620');
        $succeedsOwned->update(['platform_connection_id' => $connection->id, 'user_id' => $connection->user_id]);

        app(GameMatcher::class)->matchConnection($connection);

        $this->assertNull($failingGame->fresh()->igdb_id);
        $this->assertSame(72, $succeedsGame->fresh()->igdb_id);
    }

    /**
     * V27: raw title miss retries once with trademark glyphs stripped.
     */
    public function test_trademark_glyphs_stripped_on_retry(): void
    {
        Http::fake([
            'id.twitch.tv/oauth2/token*' => Http::response([
                'access_token' => 'twitch-app-token',
                'expires_in' => 3600,
            ]),
            'api.igdb.com/v4/games' => Http::sequence()
                ->push([]) // raw "LEGO® Worlds" search — miss
                ->push([[
                    'id' => 4001,
                    'name' => 'LEGO Worlds',
                    'genres' => [],
                ]]),
            'api.igdb.com/v4/game_time_to_beats' => Http::response([]),
        ]);
        [, $game, $connection] = $this->provisionalOwnedGame('LEGO® Worlds', '351080');

        app(GameMatcher::class)->matchConnection($connection);

        $game->refresh();
        $this->assertSame(4001, $game->igdb_id);
        $this->assertSame('LEGO Worlds', $game->title);

        $requests = Http::recorded(fn ($request) => str_contains($request->url(), 'api.igdb.com/v4/games'))
            ->values();
        $this->assertCount(2, $requests);
        $this->assertStringContainsString('LEGO® Worlds', $requests[0][0]->body());
        $this->assertStringContainsString('LEGO Worlds', $requests[1][0]->body());
        $this->assertStringNotContainsString('®', $requests[1][0]->body());
    }

    /**
     * V27: no retry (no extra IGDB call) when stripping wouldn't change the
     * query — a genuine miss stays a single request.
     */
    public function test_no_fallback_retry_when_title_has_no_trademark_glyphs(): void
    {
        $this->fakeIgdb([]);
        [, $game, $connection] = $this->provisionalOwnedGame('VoiceAttack', '780200');

        app(GameMatcher::class)->matchConnection($connection);

        $game->refresh();
        $this->assertNull($game->igdb_id);

        $requests = Http::recorded(fn ($request) => str_contains($request->url(), 'api.igdb.com/v4/games'));
        $this->assertCount(1, $requests);
    }

    public function test_sync_job_matches_after_ingestion(): void
    {
        Http::fake([
            'api.steampowered.com/IPlayerService/GetOwnedGames/*' => Http::response([
                'response' => [
                    'game_count' => 1,
                    'games' => [
                        ['appid' => 620, 'name' => 'Portal 2', 'playtime_forever' => 1200],
                    ],
                ],
            ]),
            'id.twitch.tv/oauth2/token*' => Http::response([
                'access_token' => 'twitch-app-token',
                'expires_in' => 3600,
            ]),
            'api.igdb.com/v4/games' => Http::response([$this->portalIgdbRecord()]),
            'api.igdb.com/v4/game_time_to_beats' => Http::response([]),
        ]);
        $connection = PlatformConnection::factory()->create(['platform' => 'steam']);

        (new SyncConnection($connection->id))->handle();

        $this->assertSame('ok', $connection->fresh()->status->value);
        $this->assertDatabaseHas('games', ['igdb_id' => 72, 'title' => 'Portal 2']);
    }

    /**
     * IGDB being down must not fail the sync — library data already landed
     * (V11 spirit: matching is enrichment, never a gate).
     */
    public function test_igdb_failure_does_not_break_sync(): void
    {
        Http::fake([
            'api.steampowered.com/IPlayerService/GetOwnedGames/*' => Http::response([
                'response' => [
                    'game_count' => 1,
                    'games' => [
                        ['appid' => 620, 'name' => 'Portal 2', 'playtime_forever' => 1200],
                    ],
                ],
            ]),
            'id.twitch.tv/oauth2/token*' => Http::response(null, 500),
        ]);
        $connection = PlatformConnection::factory()->create(['platform' => 'steam']);

        (new SyncConnection($connection->id))->handle();

        $this->assertSame('ok', $connection->fresh()->status->value);
        $this->assertDatabaseHas('games', ['title' => 'Portal 2', 'igdb_id' => null]);
    }
}
