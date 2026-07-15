<?php

namespace Tests\Feature\Library;

use App\Models\Game;
use App\Models\OwnedGame;
use App\Models\PlatformConnection;
use App\Models\User;
use App\Models\UserGameMeta;
use App\Models\WishlistItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * T38/V42: GET /api/library is a query-time union of owned_games,
 * wishlist_items and meta-orphans, each entry carrying a library_status.
 */
class LibraryUnionTest extends TestCase
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

    private function game(string $title, array $attrs = []): Game
    {
        return Game::create(['title' => $title, ...$attrs]);
    }

    private function own(Game $game, ?int $playtime = null, bool $freeToPlay = false): OwnedGame
    {
        return OwnedGame::create([
            'user_id' => $this->user->id,
            'platform_connection_id' => $this->connection->id,
            'game_id' => $game->id,
            'platform_game_id' => (string) fake()->unique()->numberBetween(1, 999999),
            'playtime_minutes' => $playtime,
            'added_at' => '2026-01-01 00:00:00',
            'free_to_play' => $freeToPlay,
        ]);
    }

    private function wish(Game $game, ?string $suppressedAt = null): WishlistItem
    {
        return WishlistItem::create([
            'user_id' => $this->user->id,
            'game_id' => $game->id,
            'added_at' => '2026-02-01 00:00:00',
            'origin' => 'local',
            'suppressed_at' => $suppressedAt,
        ]);
    }

    private function meta(Game $game, array $attrs = []): UserGameMeta
    {
        return UserGameMeta::create([
            'user_id' => $this->user->id,
            'game_id' => $game->id,
            ...$attrs,
        ]);
    }

    /**
     * V42/V21: wishlist rows join the union view — empty platforms, null
     * playtime, library_status=wishlist.
     */
    public function test_wishlist_items_appear_with_wishlist_status(): void
    {
        $this->own($this->game('Owned Game'), 10);
        $this->wish($this->game('Wished Game'));

        $entries = collect($this->getJson('/api/library')->assertOk()->json())->keyBy('title');

        $this->assertCount(2, $entries);
        $wished = $entries['Wished Game'];
        $this->assertSame('wishlist', $wished['library_status']);
        $this->assertSame([], $wished['platforms']);
        $this->assertNull($wished['total_playtime_minutes']);
        $this->assertSame('owned', $entries['Owned Game']['library_status']);
    }

    // V22: a locally-deleted (tombstoned) wish is not a library entry.
    public function test_suppressed_wishlist_items_excluded(): void
    {
        $this->wish($this->game('Deleted Wish'), suppressedAt: '2026-03-01 00:00:00');

        $this->getJson('/api/library')->assertOk()->assertExactJson([]);
    }

    /**
     * V42: meta rows whose game has no owned or wishlist row (survive
     * V6/V24) appear as library_status=none.
     */
    public function test_meta_orphans_appear_with_none_status(): void
    {
        $orphan = $this->game('Formerly Owned');
        $this->meta($orphan, ['status' => 'finished', 'rating' => 4]);

        $entries = $this->getJson('/api/library')->assertOk()->json();

        $this->assertCount(1, $entries);
        $this->assertSame('none', $entries[0]['library_status']);
        $this->assertSame('finished', $entries[0]['status']);
        $this->assertSame(4, $entries[0]['rating']);
        $this->assertSame([], $entries[0]['platforms']);
    }

    /**
     * V42 precedence: free only when every owning row is F2P (T37); one
     * paid row makes the game owned.
     */
    public function test_free_status_requires_all_rows_free_to_play(): void
    {
        $this->own($this->game('Pure F2P'), 30, freeToPlay: true);

        $mixed = $this->game('Mixed Game');
        $this->own($mixed, 30, freeToPlay: true);
        OwnedGame::create([
            'user_id' => $this->user->id,
            'platform_connection_id' => PlatformConnection::factory()->create([
                'user_id' => $this->user->id,
                'platform' => 'gog',
                'status' => 'ok',
            ])->id,
            'game_id' => $mixed->id,
            'platform_game_id' => (string) fake()->unique()->numberBetween(1, 999999),
            'playtime_minutes' => 5,
            'added_at' => '2026-01-01 00:00:00',
            'free_to_play' => false,
        ]);

        $entries = collect($this->getJson('/api/library')->assertOk()->json())->keyBy('title');

        $this->assertSame('free', $entries['Pure F2P']['library_status']);
        $this->assertSame('owned', $entries['Mixed Game']['library_status']);
    }

    // I.api: library_status[] multi-select.
    public function test_filters_by_library_status(): void
    {
        $this->own($this->game('Owned Game'), 10);
        $this->own($this->game('Free Game'), 10, freeToPlay: true);
        $this->wish($this->game('Wished Game'));
        $this->meta($this->game('Orphan Game'), ['status' => 'finished']);

        $titles = array_column(
            $this->getJson('/api/library?library_status[]=wishlist&library_status[]=none')->assertOk()->json(),
            'title',
        );
        sort($titles);

        $this->assertSame(['Orphan Game', 'Wished Game'], $titles);
    }

    public function test_rejects_invalid_library_status(): void
    {
        $this->getJson('/api/library?library_status[]=bogus')->assertUnprocessable();
    }

    // V28: hidden exclusion applies across the whole union.
    public function test_hidden_wishlist_game_excluded_by_default(): void
    {
        $wished = $this->game('Hidden Wish');
        $this->wish($wished);
        $this->meta($wished, ['hidden' => true]);

        $this->getJson('/api/library')->assertOk()->assertExactJson([]);

        $titles = array_column(
            $this->getJson('/api/library?include_hidden=1')->assertOk()->json(),
            'title',
        );
        $this->assertSame(['Hidden Wish'], $titles);
    }

    public function test_does_not_leak_other_users_wishlist_or_meta(): void
    {
        $other = User::factory()->create();
        $game = $this->game('Theirs');
        WishlistItem::create([
            'user_id' => $other->id,
            'game_id' => $game->id,
            'added_at' => '2026-02-01 00:00:00',
            'origin' => 'local',
        ]);
        UserGameMeta::create(['user_id' => $other->id, 'game_id' => $this->game('Their Orphan')->id]);

        $this->getJson('/api/library')->assertOk()->assertExactJson([]);
    }

    /**
     * I.api/V36: facet vocabulary stays owned-games-only — union rows
     * don't widen it.
     */
    public function test_facets_exclude_wishlist_and_orphan_vocabulary(): void
    {
        $this->own($this->game('Owned Puzzler', ['genres' => ['Puzzle']]), 10);
        $this->wish($this->game('Wished Horror', ['genres' => ['Horror']]));
        $this->meta($this->game('Orphan Racer', ['genres' => ['Racing']]), ['status' => 'finished']);

        $facets = $this->getJson('/api/library/facets')->assertOk()->json();

        $this->assertSame(['Puzzle'], $facets['genres']);
    }

    /**
     * V42: system collections count owned+free only — a wishlist game and
     * a declared-unplayed orphan never qualify as unplayed.
     */
    public function test_system_collections_exclude_wishlist_and_none(): void
    {
        $this->own($this->game('Owned Unplayed'), 0);
        $this->wish($this->game('Wished Game'));
        $this->meta($this->game('Orphan Game'), ['status' => 'unplayed']);

        $titles = array_column(
            $this->getJson('/api/library?collection=unplayed')->assertOk()->json(),
            'title',
        );

        $this->assertSame(['Owned Unplayed'], $titles);
    }

    // V42: show resolves union rows too — wishlist entry is not a 404.
    public function test_show_returns_wishlist_entry(): void
    {
        $wished = $this->game('Wished Game');
        $this->wish($wished);

        $response = $this->getJson("/api/library/{$wished->id}")->assertOk();

        $this->assertSame('wishlist', $response->json('library_status'));
        $this->assertSame([], $response->json('platforms'));
    }

    public function test_show_returns_orphan_entry(): void
    {
        $orphan = $this->game('Formerly Owned');
        $this->meta($orphan, ['status' => 'finished']);

        $this->getJson("/api/library/{$orphan->id}")
            ->assertOk()
            ->assertJsonPath('library_status', 'none');
    }

    public function test_show_still_404_when_game_nowhere_in_union(): void
    {
        $game = $this->game('Stranger');

        $this->getJson("/api/library/{$game->id}")->assertNotFound();
    }

    // V42: owned entries report their status on the single-entry route too.
    public function test_show_returns_owned_status(): void
    {
        $game = $this->game('Owned Game');
        $this->own($game, 10);

        $this->getJson("/api/library/{$game->id}")
            ->assertOk()
            ->assertJsonPath('library_status', 'owned');
    }
}
