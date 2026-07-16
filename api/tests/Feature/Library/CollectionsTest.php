<?php

namespace Tests\Feature\Library;

use App\Models\Game;
use App\Models\OwnedGame;
use App\Models\PlatformConnection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CollectionsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->withToken($this->user->createToken('t')->plainTextToken);
    }

    public function test_lists_system_collections(): void
    {
        $response = $this->getJson('/api/collections')->assertOk();

        $slugs = array_column($response->json('system'), 'slug');
        $this->assertSame(
            ['unplayed', 'abandoned', 'quick_wins', 'favorites', 'achievement_hunt'],
            $slugs,
        );
    }

    public function test_creates_and_lists_custom_collection(): void
    {
        $this->postJson('/api/collections', [
            'name' => 'Cozy puzzle nights',
            'filters' => ['genre' => 'Puzzle', 'status' => 'unplayed'],
        ])->assertCreated()->assertJsonPath('name', 'Cozy puzzle nights');

        $response = $this->getJson('/api/collections')->assertOk();

        $this->assertCount(1, $response->json('custom'));
        $this->assertSame(
            ['genre' => 'Puzzle', 'status' => 'unplayed'],
            $response->json('custom.0.filters'),
        );
    }

    public function test_custom_collections_are_private_per_user(): void
    {
        $this->postJson('/api/collections', [
            'name' => 'Mine',
            'filters' => ['genre' => 'RPG'],
        ])->assertCreated();

        $other = User::factory()->create();
        // Laravel memoizes the resolved guard within a test; drop it so the
        // second request authenticates as the new token's user.
        $this->app['auth']->forgetGuards();
        $this->withToken($other->createToken('t')->plainTextToken);

        $this->assertCount(0, $this->getJson('/api/collections')->json('custom'));
    }

    public function test_collection_validation(): void
    {
        $this->postJson('/api/collections', [
            'filters' => 'not-an-object',
        ])->assertUnprocessable()->assertJsonValidationErrors(['name', 'filters']);
    }

    /**
     * V29: manual ⊥ has filters — enforced server-side regardless of what's
     * posted.
     */
    public function test_creates_manual_collection_with_null_filters(): void
    {
        $response = $this->postJson('/api/collections', [
            'name' => 'Backlog picks',
            'type' => 'manual',
            'filters' => ['genre' => 'RPG'],
        ])->assertCreated();

        $this->assertSame('manual', $response->json('type'));
        $this->assertNull($response->json('filters'));
        $this->assertDatabaseHas('collections', ['name' => 'Backlog picks', 'type' => 'manual', 'filters' => null]);
    }

    public function test_filters_still_required_for_filter_type_collection(): void
    {
        $this->postJson('/api/collections', ['name' => 'No filters'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['filters']);
    }

    /**
     * T44/V44: every param the sidebar can emit round-trips into a saved filter
     * collection — no silent key drop. Guards against a facet being added to
     * the sidebar without being whitelisted.
     */
    public function test_full_sidebar_vocabulary_saves_verbatim(): void
    {
        $filters = [
            'sort' => 'alpha', 'order' => 'asc', 'platform' => 'steam',
            'genre' => 'RPG', 'theme' => 'Fantasy', 'keyword' => 'dragon',
            'game_mode' => 'Single player', 'status' => 'unplayed', 'tags' => 'fav',
            'unplayed' => true, 'playtime_min' => 0, 'playtime_max' => 100,
            'deck_status' => ['verified'], 'esrb' => ['M', 'none'],
            'multiplayer' => true, 'coop' => true, 'local_multiplayer' => true,
            'local_coop' => true, 'q' => 'witch', 'library_status' => ['owned'],
            'rating' => ['5', 'none'],
        ];

        $response = $this->postJson('/api/collections', [
            'name' => 'Everything', 'type' => 'filter', 'filters' => $filters,
        ])->assertCreated();

        $this->assertEquals($filters, $response->json('filters'));
    }

    public function test_unknown_filter_key_rejected(): void
    {
        $this->postJson('/api/collections', [
            'name' => 'Bad', 'type' => 'filter',
            'filters' => ['genre' => 'RPG', 'bogus' => 1],
        ])->assertUnprocessable()->assertJsonValidationErrors(['filters']);
    }

    /**
     * T44/V44: a saved filter collection evaluated via `?collection=id` yields
     * exactly what the same filters applied directly would — saved ≡ re-checking
     * the facets (LibraryQuery, V29).
     */
    public function test_saved_filter_collection_matches_direct_params(): void
    {
        $rpg = Game::create(['title' => 'Elden Ring', 'genres' => ['RPG']]);
        $puzzle = Game::create(['title' => 'Baba Is You', 'genres' => ['Puzzle']]);
        $connection = PlatformConnection::factory()->create([
            'user_id' => $this->user->id, 'platform' => 'steam', 'status' => 'ok',
        ]);
        foreach ([$rpg, $puzzle] as $game) {
            OwnedGame::create([
                'user_id' => $this->user->id,
                'platform_connection_id' => $connection->id,
                'game_id' => $game->id,
                'platform_game_id' => (string) fake()->unique()->numberBetween(1, 999999),
                'added_at' => now(),
            ]);
        }

        $id = $this->postJson('/api/collections', [
            'name' => 'RPGs', 'type' => 'filter', 'filters' => ['genre' => 'RPG'],
        ])->json('id');

        $viaCollection = array_column($this->getJson("/api/library?collection={$id}")->json(), 'title');
        $viaDirect = array_column($this->getJson('/api/library?genre=RPG')->json(), 'title');

        $this->assertSame(['Elden Ring'], $viaCollection);
        $this->assertSame($viaDirect, $viaCollection);
    }

    private function ownedGame(string $title): Game
    {
        $game = Game::create(['title' => $title]);
        $connection = PlatformConnection::factory()->create([
            'user_id' => $this->user->id,
            'platform' => 'steam',
            'status' => 'ok',
        ]);
        OwnedGame::create([
            'user_id' => $this->user->id,
            'platform_connection_id' => $connection->id,
            'game_id' => $game->id,
            'platform_game_id' => (string) fake()->unique()->numberBetween(1, 999999),
            'added_at' => now(),
        ]);

        return $game;
    }

    public function test_manual_collection_add_remove_and_library_membership(): void
    {
        $collectionId = $this->postJson('/api/collections', [
            'name' => 'Backlog picks',
            'type' => 'manual',
        ])->json('id');
        $game = $this->ownedGame('Hollow Knight');
        $other = $this->ownedGame('Celeste');

        $this->postJson("/api/collections/{$collectionId}/games", ['game_id' => $game->id])
            ->assertCreated();

        // Idempotent re-add.
        $this->postJson("/api/collections/{$collectionId}/games", ['game_id' => $game->id])
            ->assertOk();
        $this->assertDatabaseCount('collection_games', 1);

        $titles = array_column(
            $this->getJson("/api/library?collection={$collectionId}")->assertOk()->json(),
            'title',
        );
        $this->assertSame(['Hollow Knight'], $titles);

        $this->deleteJson("/api/collections/{$collectionId}/games/{$game->id}")->assertOk();
        $this->assertDatabaseCount('collection_games', 0);
        $this->assertSame(
            [],
            $this->getJson("/api/library?collection={$collectionId}")->assertOk()->json(),
        );

        // Idempotent remove — never a member of $other to begin with.
        $this->deleteJson("/api/collections/{$collectionId}/games/{$other->id}")->assertOk();
    }

    public function test_add_game_rejected_on_filter_type_collection(): void
    {
        $collectionId = $this->postJson('/api/collections', [
            'name' => 'Cozy puzzle nights',
            'filters' => ['genre' => 'Puzzle'],
        ])->json('id');
        $game = $this->ownedGame('Portal 2');

        $this->postJson("/api/collections/{$collectionId}/games", ['game_id' => $game->id])
            ->assertUnprocessable();
    }

    public function test_collection_games_are_private_per_user(): void
    {
        $collectionId = $this->postJson('/api/collections', [
            'name' => 'Backlog picks',
            'type' => 'manual',
        ])->json('id');

        $other = User::factory()->create();
        $this->app['auth']->forgetGuards();
        $this->withToken($other->createToken('t')->plainTextToken);
        $game = Game::create(['title' => 'Someone Elses Game']);

        $this->postJson("/api/collections/{$collectionId}/games", ['game_id' => $game->id])
            ->assertNotFound();
    }
}
