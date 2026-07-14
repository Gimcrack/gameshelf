<?php

namespace App\Services\Library;

use App\Enums\ConnectionStatus;
use App\Models\Game;
use App\Models\OwnedGame;
use App\Models\PlatformConnection;
use App\Models\User;
use Illuminate\Support\Facades\Date;

class ManualLibrary
{
    /**
     * V19: manual entries hang off one synthetic per-user connection —
     * no tokens, never synced, invisible in the connections UI.
     */
    public function connectionFor(User $user): PlatformConnection
    {
        return PlatformConnection::firstOrCreate(
            ['user_id' => $user->id, 'platform' => 'manual'],
            ['external_account_id' => 'manual', 'status' => ConnectionStatus::Ok],
        );
    }

    /**
     * Add a game to the user's library by hand. Returns the owned row and
     * whether it was newly created; an already-owned game (any platform)
     * is a no-op returning the existing row (V19, V10).
     *
     * @return array{OwnedGame, bool}
     */
    public function add(User $user, Game $game): array
    {
        $existing = OwnedGame::where('user_id', $user->id)
            ->where('game_id', $game->id)
            ->first();

        if ($existing !== null) {
            return [$existing, false];
        }

        $owned = OwnedGame::create([
            'user_id' => $user->id,
            'platform_connection_id' => $this->connectionFor($user)->id,
            'game_id' => $game->id,
            'platform_game_id' => (string) $game->igdb_id,
            'playtime_minutes' => null,
            'added_at' => Date::now(),
        ]);

        return [$owned, true];
    }

    /**
     * V19: removal only touches the manual row — platform-synced ownership
     * is never deletable by hand.
     */
    public function remove(User $user, Game $game): bool
    {
        $deleted = OwnedGame::where('user_id', $user->id)
            ->where('game_id', $game->id)
            ->whereHas('connection', fn ($query) => $query->where('platform', 'manual'))
            ->delete();

        return $deleted > 0;
    }
}
