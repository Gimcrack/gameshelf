<?php

namespace Tests\Feature\Library;

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
}
