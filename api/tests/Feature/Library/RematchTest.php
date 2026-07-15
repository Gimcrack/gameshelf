<?php

namespace Tests\Feature\Library;

use App\Models\Game;
use App\Models\OwnedGame;
use App\Models\PlatformConnection;
use App\Models\User;
use App\Models\UserGameMeta;
use App\Models\WishlistItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RematchTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->withToken($this->user->createToken('api')->plainTextToken);
    }

    private function fakeIgdbGame(int $igdbId, string $title): void
    {
        Http::fake([
            'id.twitch.tv/oauth2/token*' => Http::response([
                'access_token' => 'twitch-app-token',
                'expires_in' => 3600,
            ]),
            'api.igdb.com/v4/games' => Http::response([
                ['id' => $igdbId, 'name' => $title, 'genres' => []],
            ]),
            'api.igdb.com/v4/game_time_to_beats' => Http::response([]),
        ]);
    }

    private function own(PlatformConnection $connection, Game $game, string $platformGameId = 'x'): OwnedGame
    {
        return OwnedGame::create([
            'user_id' => $connection->user_id,
            'platform_connection_id' => $connection->id,
            'game_id' => $game->id,
            'platform_game_id' => $platformGameId,
            'added_at' => now(),
        ]);
    }

    private function connection(): PlatformConnection
    {
        return PlatformConnection::factory()->create(['user_id' => $this->user->id, 'status' => 'ok']);
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

    /**
     * V34: provisional miss corrected — new canonical row created, entry
     * now shows the fixed title.
     */
    public function test_rematch_creates_canonical_row_for_new_igdb_id(): void
    {
        $this->fakeIgdbGame(500, 'Correct Title');
        $provisional = Game::create(['title' => 'Wrong Guess']);
        $this->own($this->connection(), $provisional);

        $response = $this->postJson("/api/library/{$provisional->id}/rematch", ['igdb_id' => 500])
            ->assertOk();

        $this->assertSame('Correct Title', $response->json('title'));
        $this->assertDatabaseHas('games', ['igdb_id' => 500, 'title' => 'Correct Title']);
        // Orphaned provisional row (still igdb_id null, now unowned) is gone.
        $this->assertDatabaseMissing('games', ['id' => $provisional->id]);
    }

    /**
     * V34/V7: an already-matched (but wrong) game is also correctable —
     * fix match isn't provisional-only.
     */
    public function test_rematch_corrects_an_already_matched_game(): void
    {
        $this->fakeIgdbGame(501, 'Actually This One');
        $wrong = Game::create(['igdb_id' => 999, 'title' => 'Wrong Match']);
        $this->own($this->connection(), $wrong);

        $this->postJson("/api/library/{$wrong->id}/rematch", ['igdb_id' => 501])->assertOk();

        $this->assertDatabaseHas('owned_games', ['user_id' => $this->user->id, 'game_id' => Game::where('igdb_id', 501)->firstOrFail()->id]);
        // Already-matched row is real data — never deleted, even unowned now.
        $this->assertDatabaseHas('games', ['id' => $wrong->id, 'igdb_id' => 999]);
    }

    /**
     * V7/V34: reuses an existing canonical row for the chosen igdb_id
     * rather than duplicating it.
     */
    public function test_rematch_reuses_existing_canonical_row(): void
    {
        $this->fakeIgdbGame(502, 'Shared Canonical');
        $existing = Game::create(['igdb_id' => 502, 'title' => 'Shared Canonical']);
        $provisional = Game::create(['title' => 'Guess']);
        $this->own($this->connection(), $provisional);

        $this->postJson("/api/library/{$provisional->id}/rematch", ['igdb_id' => 502])->assertOk();

        $this->assertSame(1, Game::where('igdb_id', 502)->count());
        $this->assertDatabaseHas('owned_games', ['user_id' => $this->user->id, 'game_id' => $existing->id]);
    }

    /**
     * V34: only the caller's own owned_games rows are repointed — another
     * user's rows pointing at the same wrong game are untouched.
     */
    public function test_rematch_only_repoints_callers_own_rows(): void
    {
        $this->fakeIgdbGame(503, 'Fixed For Me');
        $wrong = Game::create(['igdb_id' => 998, 'title' => 'Wrong For Everyone']);
        $mine = $this->own($this->connection(), $wrong);
        $other = User::factory()->create();
        $othersConnection = PlatformConnection::factory()->create(['user_id' => $other->id, 'status' => 'ok']);
        $theirs = $this->own($othersConnection, $wrong, 'y');

        $this->postJson("/api/library/{$wrong->id}/rematch", ['igdb_id' => 503])->assertOk();

        $this->assertSame(Game::where('igdb_id', 503)->firstOrFail()->id, $mine->fresh()->game_id);
        $this->assertSame($wrong->id, $theirs->fresh()->game_id);
    }

    public function test_rematch_to_currently_matched_id_is_a_noop(): void
    {
        $game = Game::create(['igdb_id' => 504, 'title' => 'Already Right']);
        $this->own($this->connection(), $game);

        $response = $this->postJson("/api/library/{$game->id}/rematch", ['igdb_id' => 504])->assertOk();

        $this->assertSame($game->id, $response->json('id'));
        $this->assertDatabaseCount('games', 1);
    }

    public function test_rematch_404_when_game_not_in_library(): void
    {
        // Not owned, wishlisted, nor meta'd by the caller — B13/T47 union
        // membership still excludes it.
        $game = Game::create(['igdb_id' => 505, 'title' => 'Not Mine']);

        $this->postJson("/api/library/{$game->id}/rematch", ['igdb_id' => 505])->assertNotFound();
    }

    /**
     * B13/V34/T47: a wishlist-only game (no owned row — e.g. B11 provisional)
     * is fixable; the wishlist row is repointed to the corrected canonical row.
     */
    public function test_rematch_wishlist_only_game(): void
    {
        $this->fakeIgdbGame(510, 'Correct Wishlist Title');
        $provisional = Game::create(['title' => 'Provisional Wish']);
        $wish = $this->wish($this->user, $provisional);

        $response = $this->postJson("/api/library/{$provisional->id}/rematch", ['igdb_id' => 510])
            ->assertOk();

        $this->assertSame('Correct Wishlist Title', $response->json('title'));
        $target = Game::where('igdb_id', 510)->firstOrFail();
        $this->assertSame($target->id, $wish->fresh()->game_id);
        // Old provisional had no other refs → cleaned up.
        $this->assertDatabaseMissing('games', ['id' => $provisional->id]);
    }

    /**
     * B13/V34/T47: a meta-orphan (user_game_meta only, no owned/wishlist) is
     * fixable — the meta follows the game so status/rating survive (V6 content).
     */
    public function test_rematch_meta_orphan_game(): void
    {
        $this->fakeIgdbGame(511, 'Correct Meta Title');
        $provisional = Game::create(['title' => 'Provisional Meta']);
        $meta = UserGameMeta::create([
            'user_id' => $this->user->id,
            'game_id' => $provisional->id,
            'status' => 'finished',
            'rating' => 5,
        ]);

        $this->postJson("/api/library/{$provisional->id}/rematch", ['igdb_id' => 511])->assertOk();

        $target = Game::where('igdb_id', 511)->firstOrFail();
        $this->assertSame($target->id, $meta->fresh()->game_id);
        $this->assertSame(5, (int) $meta->fresh()->rating);
        $this->assertDatabaseMissing('games', ['id' => $provisional->id]);
    }

    /**
     * V21: rematching a wishlist game onto an igdb_id the caller already owns
     * must not create an owned ∩ wishlist overlap — the wishlist row is dropped.
     */
    public function test_rematch_wishlist_onto_owned_drops_wishlist_row_v21(): void
    {
        $this->fakeIgdbGame(512, 'Owned Already');
        $owned = Game::create(['igdb_id' => 512, 'title' => 'Owned Already']);
        $this->own($this->connection(), $owned);
        $provisional = Game::create(['title' => 'Wish Dupe']);
        $this->wish($this->user, $provisional);

        $this->postJson("/api/library/{$provisional->id}/rematch", ['igdb_id' => 512])->assertOk();

        // V21: no wishlist row survives for the now-owned game.
        $this->assertDatabaseMissing('wishlist_items', [
            'user_id' => $this->user->id,
            'game_id' => $owned->id,
        ]);
        $this->assertSame(0, WishlistItem::where('user_id', $this->user->id)->count());
        $this->assertDatabaseMissing('games', ['id' => $provisional->id]);
    }

    /**
     * V34/T47: orphan cleanup respects the union — a provisional row still
     * wishlisted by another user is real data, kept when the caller rematches.
     */
    public function test_rematch_keeps_provisional_still_referenced_by_other_user(): void
    {
        $this->fakeIgdbGame(513, 'Fixed For Me Only');
        $provisional = Game::create(['title' => 'Shared Provisional']);
        $mine = $this->wish($this->user, $provisional);
        $other = User::factory()->create();
        $theirs = $this->wish($other, $provisional);

        $this->postJson("/api/library/{$provisional->id}/rematch", ['igdb_id' => 513])->assertOk();

        $target = Game::where('igdb_id', 513)->firstOrFail();
        $this->assertSame($target->id, $mine->fresh()->game_id);
        // Other user still references it → not deleted, their row untouched.
        $this->assertDatabaseHas('games', ['id' => $provisional->id]);
        $this->assertSame($provisional->id, $theirs->fresh()->game_id);
    }

    public function test_rematch_rejects_unknown_igdb_id(): void
    {
        Http::fake([
            'id.twitch.tv/oauth2/token*' => Http::response([
                'access_token' => 'twitch-app-token',
                'expires_in' => 3600,
            ]),
            'api.igdb.com/v4/games' => Http::response([]),
        ]);
        $game = Game::create(['title' => 'Provisional']);
        $this->own($this->connection(), $game);

        $this->postJson("/api/library/{$game->id}/rematch", ['igdb_id' => 999999999])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['igdb_id']);
    }

    public function test_rematch_requires_auth(): void
    {
        $game = Game::create(['igdb_id' => 506, 'title' => 'X']);

        $this->withHeaders(['Authorization' => ''])
            ->postJson("/api/library/{$game->id}/rematch", ['igdb_id' => 506])
            ->assertUnauthorized();
    }
}
