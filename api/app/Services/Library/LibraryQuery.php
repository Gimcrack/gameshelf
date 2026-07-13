<?php

namespace App\Services\Library;

use App\Models\OwnedGame;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * V1: owned_games keeps one row per (user, platform, game); this service
 * dedupes to one entry per game at query time.
 */
class LibraryQuery
{
    /**
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>
     */
    public function forUser(User $user, array $filters): array
    {
        $entries = OwnedGame::query()
            ->where('user_id', $user->id)
            ->with(['game', 'connection'])
            ->get()
            ->groupBy('game_id')
            ->map(fn (Collection $group) => $this->entry($group))
            ->values();

        return $this->sort($this->filter($entries, $filters), $filters)
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, OwnedGame>  $group
     * @return array<string, mixed>
     */
    private function entry(Collection $group): array
    {
        $game = $group->first()->game;
        $knownPlaytimes = $group->pluck('playtime_minutes')->reject(fn ($v) => $v === null);

        return [
            'id' => $game->id,
            'igdb_id' => $game->igdb_id,
            'title' => $game->title,
            'cover_url' => $game->cover_url,
            'genres' => $game->genres ?? [],
            'release_date' => $game->release_date?->toDateString(),
            'platforms' => $group->map(fn (OwnedGame $owned) => [
                'platform' => $owned->connection->platform,
                // V13: disconnected status flows through for UI badges.
                'connection_status' => $owned->connection->status->value,
                'playtime_minutes' => $owned->playtime_minutes,
                'last_played_at' => $owned->last_played_at?->toIso8601String(),
            ])->values()->all(),
            // V12: all-null playtime stays null (unknown), never 0.
            'total_playtime_minutes' => $knownPlaytimes->isEmpty() ? null : $knownPlaytimes->sum(),
            'last_played_at' => $group->max('last_played_at')?->toIso8601String(),
            'added_at' => $group->min('added_at')?->toIso8601String(),
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $entries
     * @param  array<string, mixed>  $filters
     * @return Collection<int, array<string, mixed>>
     */
    private function filter(Collection $entries, array $filters): Collection
    {
        return $entries
            ->when(isset($filters['platform']), fn (Collection $c) => $c->filter(
                fn (array $e) => in_array(
                    $filters['platform'],
                    array_column($e['platforms'], 'platform'),
                    true,
                ),
            ))
            ->when(isset($filters['genre']), fn (Collection $c) => $c->filter(
                fn (array $e) => in_array($filters['genre'], $e['genres'], true),
            ))
            ->when(isset($filters['playtime_min']), fn (Collection $c) => $c->filter(
                fn (array $e) => $e['total_playtime_minutes'] !== null
                    && $e['total_playtime_minutes'] >= (int) $filters['playtime_min'],
            ))
            ->when(isset($filters['playtime_max']), fn (Collection $c) => $c->filter(
                fn (array $e) => $e['total_playtime_minutes'] !== null
                    && $e['total_playtime_minutes'] <= (int) $filters['playtime_max'],
            ))
            // V12: unplayed means known-zero; unknown (null) is excluded.
            ->when(! empty($filters['unplayed']), fn (Collection $c) => $c->filter(
                fn (array $e) => $e['total_playtime_minutes'] === 0,
            ));
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $entries
     * @param  array<string, mixed>  $filters
     * @return Collection<int, array<string, mixed>>
     */
    private function sort(Collection $entries, array $filters): Collection
    {
        $sort = $filters['sort'] ?? 'alpha';
        $descending = ($filters['order'] ?? 'asc') === 'desc';

        if ($sort === 'alpha') {
            return $entries->sortBy('title', SORT_NATURAL | SORT_FLAG_CASE, $descending);
        }

        $key = match ($sort) {
            'playtime' => 'total_playtime_minutes',
            'last_played' => 'last_played_at',
            'added' => 'added_at',
        };

        // Nulls (unknown values) always sort last, regardless of direction.
        [$known, $unknown] = $entries->partition(fn (array $e) => $e[$key] !== null);

        return $known->sortBy(fn (array $e) => $e[$key], SORT_REGULAR, $descending)
            ->concat($unknown);
    }
}
