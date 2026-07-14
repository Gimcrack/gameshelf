<?php

namespace Tests\Feature\Discover;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DiscoverBrowseTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::factory()->create();
        $this->withToken($user->createToken('api')->plainTextToken);
    }

    private function fakeIgdb(): void
    {
        Http::fake([
            'id.twitch.tv/oauth2/token*' => Http::response([
                'access_token' => 'twitch-app-token',
                'expires_in' => 3600,
            ]),
            'api.igdb.com/v4/genres' => Http::response([
                ['id' => 12, 'name' => 'Role-playing (RPG)'],
                ['id' => 32, 'name' => 'Indie'],
            ]),
            'api.igdb.com/v4/games' => Http::response([
                ['id' => 1020, 'name' => 'Grand Theft Auto V', 'total_rating' => 90.1],
            ]),
        ]);
    }

    private function igdbGamesBody(): string
    {
        $call = Http::recorded(
            fn (Request $request) => str_contains($request->url(), 'api.igdb.com/v4/games'),
        )->first();

        $this->assertNotNull($call);

        return $call[0]->body();
    }

    public function test_browse_default_sorts_by_popularity(): void
    {
        $this->fakeIgdb();

        $hits = $this->getJson('/api/discover/browse')->assertOk()->json();

        $this->assertSame('Grand Theft Auto V', $hits[0]['title']);
        $this->assertArrayHasKey('in_library', $hits[0]);
        $this->assertArrayHasKey('in_wishlist', $hits[0]);

        $body = $this->igdbGamesBody();
        $this->assertStringContainsString('sort total_rating_count desc', $body);
        $this->assertStringContainsString('where total_rating_count != null', $body);
        $this->assertStringContainsString('offset 0', $body);
    }

    public function test_sort_params_map_to_igdb_order(): void
    {
        $this->fakeIgdb();

        $this->getJson('/api/discover/browse?sort=rating')->assertOk();
        $this->assertStringContainsString('sort total_rating desc', $this->igdbGamesBody());

        Http::fake();
        $this->fakeIgdb();
        $this->getJson('/api/discover/browse?sort=release')->assertOk();
        $this->assertStringContainsString(
            'sort first_release_date desc',
            $this->igdbGamesBody(),
        );

        $this->getJson('/api/discover/browse?sort=bogus')->assertUnprocessable();
    }

    public function test_genre_name_resolves_to_igdb_id_filter(): void
    {
        $this->fakeIgdb();

        $this->getJson('/api/discover/browse?genre=indie')->assertOk();

        $this->assertStringContainsString('genres = 32', $this->igdbGamesBody());
    }

    public function test_unknown_genre_returns_empty_without_games_query(): void
    {
        $this->fakeIgdb();

        $this->assertSame([], $this->getJson('/api/discover/browse?genre=nope')->json());

        Http::assertNotSent(
            fn (Request $request) => str_contains($request->url(), 'api.igdb.com/v4/games'),
        );
    }

    public function test_page_maps_to_offset(): void
    {
        $this->fakeIgdb();

        $this->getJson('/api/discover/browse?page=3')->assertOk();

        $this->assertStringContainsString('offset 40', $this->igdbGamesBody());
    }

    /**
     * V4: repeat identical browse serves from cache — one upstream call.
     */
    public function test_repeat_browse_cached(): void
    {
        $this->fakeIgdb();

        $this->getJson('/api/discover/browse')->assertOk();
        $this->getJson('/api/discover/browse')->assertOk();

        $igdbCalls = Http::recorded(
            fn (Request $request) => str_contains($request->url(), 'api.igdb.com/v4/games'),
        );
        $this->assertCount(1, $igdbCalls);
    }
}
