<?php

namespace Tests\Feature\Sync;

use App\Jobs\MatchGameIgdb;
use App\Models\Game;
use App\Models\User;
use App\Models\UserGameMeta;
use App\Models\WishlistItem;
use App\Services\Igdb\GameMatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MatchGameIgdbTest extends TestCase
{
    use RefreshDatabase;

    private function fakeIgdb(mixed $gamesResponse): void
    {
        Http::fake([
            'id.twitch.tv/oauth2/token*' => Http::response([
                'access_token' => 'twitch-app-token',
                'expires_in' => 3600,
            ]),
            'api.igdb.com/v4/games' => $gamesResponse,
            'api.igdb.com/v4/game_time_to_beats' => Http::response([]),
        ]);
    }

    private function wish(User $user, Game $game): WishlistItem
    {
        return WishlistItem::create([
            'user_id' => $user->id,
            'game_id' => $game->id,
            'added_at' => now(),
            'origin' => 'steam',
            'steam_present' => true,
        ]);
    }

    private function match(User $user, Game $game): void
    {
        (new MatchGameIgdb($user->id, $game->id))->handle(app(GameMatcher::class));
    }

    /**
     * T50/V48/V34: a provisional wishlist-only game is matched by title and
     * its wishlist row repointed onto the resolved canonical game.
     */
    public function test_matches_provisional_wishlist_game_and_repoints(): void
    {
        $this->fakeIgdb(Http::response([['id' => 72, 'name' => 'Portal 2', 'genres' => []]]));
        $user = User::factory()->create();

        $provisional = Game::create(['title' => 'Portal 2']);
        $wish = $this->wish($user, $provisional);

        $this->match($user, $provisional);

        $this->assertSame(72, $wish->fresh()->game->igdb_id);
        // Orphan provisional removed once nothing references it (V34).
        $this->assertNull(Game::find($provisional->id));
    }

    /**
     * T50/V48: a provisional meta-orphan game is matched too — meta follows
     * the game onto the canonical row (V6/V34).
     */
    public function test_matches_provisional_meta_orphan(): void
    {
        $this->fakeIgdb(Http::response([['id' => 72, 'name' => 'Portal 2', 'genres' => []]]));
        $user = User::factory()->create();

        $provisional = Game::create(['title' => 'Portal 2']);
        $meta = UserGameMeta::create([
            'user_id' => $user->id,
            'game_id' => $provisional->id,
            'status' => 'finished',
            'rating' => 5,
        ]);

        $this->match($user, $provisional);

        $target = Game::where('igdb_id', 72)->firstOrFail();
        $this->assertSame($target->id, $meta->fresh()->game_id);
        $this->assertSame(5, $meta->fresh()->rating);
    }

    /**
     * V26: a transient IGDB failure never throws out of the job — the game
     * stays provisional and is retried next sync.
     */
    public function test_transient_failure_leaves_game_provisional(): void
    {
        $this->fakeIgdb(Http::response(null, 500));
        $user = User::factory()->create();

        $provisional = Game::create(['title' => 'Portal 2']);
        $this->wish($user, $provisional);

        $this->match($user, $provisional);

        $this->assertNotNull($provisional->fresh());
        $this->assertNull($provisional->fresh()->igdb_id);
    }

    /**
     * V11: a genuine no-match leaves the provisional row visible.
     */
    public function test_no_match_leaves_game_provisional(): void
    {
        $this->fakeIgdb(Http::response([]));
        $user = User::factory()->create();

        $provisional = Game::create(['title' => 'Nonexistent Software']);
        $this->wish($user, $provisional);

        $this->match($user, $provisional);

        $this->assertNull($provisional->fresh()->igdb_id);
    }

    /**
     * Already-matched game short-circuits — no IGDB call.
     */
    public function test_already_matched_game_is_a_no_op(): void
    {
        Http::fake();
        $user = User::factory()->create();

        $matched = Game::create(['igdb_id' => 1942, 'title' => 'The Witcher 3']);
        $this->wish($user, $matched);

        $this->match($user, $matched);

        Http::assertNothingSent();
    }

    public function test_missing_game_is_a_no_op(): void
    {
        Http::fake();
        $user = User::factory()->create();

        (new MatchGameIgdb($user->id, 999999))->handle(app(GameMatcher::class));

        Http::assertNothingSent();
    }

    /**
     * V39: rate limit enforced at the queue layer via middleware.
     */
    public function test_job_is_rate_limited_on_the_igdb_sync_limiter(): void
    {
        $middleware = (new MatchGameIgdb(1, 1))->middleware();

        $this->assertCount(1, $middleware);
        $this->assertInstanceOf(RateLimited::class, $middleware[0]);
    }
}
