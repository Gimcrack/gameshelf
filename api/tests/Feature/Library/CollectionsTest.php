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
        $this->assertSame(['unplayed', 'abandoned', 'quick_wins'], $slugs);
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
