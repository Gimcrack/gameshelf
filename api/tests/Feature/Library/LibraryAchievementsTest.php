<?php

namespace Tests\Feature\Library;

use App\Models\Game;
use App\Models\GameAchievementDef;
use App\Models\OwnedGame;
use App\Models\OwnedGameAchievement;
use App\Models\PlatformConnection;
use App\Models\User;
use App\Models\WishlistItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * T70/V63/V67: GET /api/library/:game_id/achievements + the achievements_summary
 * field carried on every /api/library entry.
 */
class LibraryAchievementsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->withToken($this->user->createToken('t')->plainTextToken);
    }

    private function game(string $title): Game
    {
        return Game::create(['title' => $title]);
    }

    private function connection(string $platform): PlatformConnection
    {
        return PlatformConnection::factory()->create([
            'user_id' => $this->user->id,
            'platform' => $platform,
            'status' => 'ok',
        ]);
    }

    private function own(PlatformConnection $connection, Game $game, string $platformGameId): OwnedGame
    {
        return OwnedGame::create([
            'user_id' => $this->user->id,
            'platform_connection_id' => $connection->id,
            'game_id' => $game->id,
            'platform_game_id' => $platformGameId,
            'added_at' => '2026-01-01 00:00:00',
        ]);
    }

    private function def(string $platform, string $platformGameId, string $apiName, array $attrs = []): GameAchievementDef
    {
        return GameAchievementDef::create([
            'platform' => $platform,
            'platform_game_id' => $platformGameId,
            'api_name' => $apiName,
            'name' => $attrs['name'] ?? $apiName,
            'description' => $attrs['description'] ?? null,
            'icon_url' => $attrs['icon_url'] ?? null,
            'points' => $attrs['points'] ?? null,
            'fetched_at' => now(),
        ]);
    }

    public function test_returns_achievement_list_for_steam_owned_game(): void
    {
        $game = $this->game('Portal 2');
        $owned = $this->own($this->connection('steam'), $game, '620');
        $unlockedDef = $this->def('steam', '620', 'TOWER_OF_ROCKETS', ['name' => 'Tower of Rockets', 'description' => 'Build a tower.']);
        $lockedDef = $this->def('steam', '620', 'RAT_MAZE', ['name' => 'Rat Maze']);
        OwnedGameAchievement::create([
            'owned_game_id' => $owned->id,
            'game_achievement_def_id' => $unlockedDef->id,
            'unlocked' => true,
            'unlocked_at' => '2026-01-05 00:00:00',
        ]);

        $response = $this->getJson("/api/library/{$game->id}/achievements");

        $response->assertOk();
        $achievements = $response->json('achievements');
        $this->assertCount(2, $achievements);
        $unlocked = collect($achievements)->firstWhere('name', 'Tower of Rockets');
        $this->assertTrue($unlocked['unlocked']);
        $this->assertNotNull($unlocked['unlocked_at']);
        $locked = collect($achievements)->firstWhere('name', 'Rat Maze');
        $this->assertFalse($locked['unlocked']);
        $this->assertNull($locked['unlocked_at']);
    }

    public function test_404_when_only_gog_owned(): void
    {
        $game = $this->game('Gwent');
        $this->own($this->connection('gog'), $game, 'gwent-id');

        $this->getJson("/api/library/{$game->id}/achievements")->assertNotFound();
    }

    public function test_404_when_manual_only(): void
    {
        $game = $this->game('Manual Game');
        $this->own($this->connection('manual'), $game, 'manual-1');

        $this->getJson("/api/library/{$game->id}/achievements")->assertNotFound();
    }

    public function test_404_when_wishlist_only(): void
    {
        $game = $this->game('Wishlisted');
        WishlistItem::create([
            'user_id' => $this->user->id,
            'game_id' => $game->id,
            'added_at' => '2026-01-01 00:00:00',
            'origin' => 'local',
        ]);

        $this->getJson("/api/library/{$game->id}/achievements")->assertNotFound();
    }

    public function test_404_when_not_in_library_at_all(): void
    {
        $game = $this->game('Untouched');

        $this->getJson("/api/library/{$game->id}/achievements")->assertNotFound();
    }

    public function test_achievements_summary_null_for_gog_only_entry(): void
    {
        $game = $this->game('Gwent');
        $this->own($this->connection('gog'), $game, 'gwent-id');

        $entry = collect($this->getJson('/api/library')->json())->firstWhere('id', $game->id);

        $this->assertNull($entry['achievements_summary']);
    }

    public function test_achievements_summary_aggregated_for_steam_owned_entry(): void
    {
        $game = $this->game('Portal 2');
        $owned = $this->own($this->connection('steam'), $game, '620');
        $unlockedDef = $this->def('steam', '620', 'TOWER_OF_ROCKETS');
        $this->def('steam', '620', 'RAT_MAZE');
        OwnedGameAchievement::create([
            'owned_game_id' => $owned->id,
            'game_achievement_def_id' => $unlockedDef->id,
            'unlocked' => true,
            'unlocked_at' => '2026-01-05 00:00:00',
        ]);

        $entry = collect($this->getJson('/api/library')->json())->firstWhere('id', $game->id);

        $this->assertSame(['unlocked' => 1, 'total' => 2], $entry['achievements_summary']);

        // Same aggregation on the single-game show route (T24/T70).
        $show = $this->getJson("/api/library/{$game->id}")->json();
        $this->assertSame(['unlocked' => 1, 'total' => 2], $show['achievements_summary']);
    }

    /**
     * Achievement totals sum across multiple achievement-capable platforms
     * for the same canonical game.
     */
    public function test_achievements_summary_sums_across_platforms(): void
    {
        $game = $this->game('Multi-platform Game');
        $steamOwned = $this->own($this->connection('steam'), $game, '620');
        $xboxOwned = $this->own($this->connection('xbox'), $game, '1153748408');
        $steamDef = $this->def('steam', '620', 'STEAM_ACH');
        $xboxDef = $this->def('xbox', '1153748408', 'XBOX_ACH');
        OwnedGameAchievement::create([
            'owned_game_id' => $steamOwned->id,
            'game_achievement_def_id' => $steamDef->id,
            'unlocked' => true,
            'unlocked_at' => '2026-01-05 00:00:00',
        ]);
        OwnedGameAchievement::create([
            'owned_game_id' => $xboxOwned->id,
            'game_achievement_def_id' => $xboxDef->id,
            'unlocked' => false,
        ]);

        $entry = collect($this->getJson('/api/library')->json())->firstWhere('id', $game->id);

        $this->assertSame(['unlocked' => 1, 'total' => 2], $entry['achievements_summary']);
    }
}
