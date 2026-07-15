<?php

namespace App\Services\Library;

use App\Models\Game;
use App\Models\OwnedGame;
use App\Models\User;
use App\Models\UserGameMeta;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Date;

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
        return $this->sort($this->filter($this->entriesFor($user), $filters), $filters)
            ->values()
            ->all();
    }

    /**
     * T28: distinct genre/theme/keyword/game_mode/platform values across
     * the caller's (non-hidden, V28/V36) library — feeds the left-sidebar
     * checkbox filters. Static full vocabulary, ⊥ responsive to other
     * active filter selections (V36).
     *
     * @return array<string, list<string>>
     */
    public function facetsForUser(User $user): array
    {
        $entries = $this->entriesFor($user)->reject(fn (array $e) => $e['hidden']);

        return [
            'genres' => $this->distinctValues($entries, 'genres'),
            'themes' => $this->distinctValues($entries, 'themes'),
            'keywords' => $this->distinctValues($entries, 'keywords'),
            'game_modes' => $this->distinctValues($entries, 'game_modes'),
            'platforms' => $entries
                ->flatMap(fn (array $e) => array_column($e['platforms'], 'platform'))
                ->unique()
                ->sort()
                ->values()
                ->all(),
        ];
    }

    /**
     * V1/V6: dedupes owned_games to one entry per game, joining user meta
     * at read time — the shared base both forUser and facetsForUser build on.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function entriesFor(User $user): Collection
    {
        $metaByGame = UserGameMeta::where('user_id', $user->id)
            ->get()
            ->keyBy('game_id');

        return OwnedGame::query()
            ->where('user_id', $user->id)
            ->with(['game', 'connection'])
            ->get()
            ->groupBy('game_id')
            ->map(fn (Collection $group) => $this->entry($group, $metaByGame->get($group->first()->game_id)))
            ->values();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $entries
     * @return list<string>
     */
    private function distinctValues(Collection $entries, string $field): array
    {
        return $entries->flatMap(fn (array $e) => $e[$field])
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    /**
     * I.api: GET /api/library/:game_id — same entry shape as the list, for
     * one game. Null when the caller owns no row for it (404 upstream).
     *
     * @return array<string, mixed>|null
     */
    public function forGame(User $user, Game $game): ?array
    {
        $group = OwnedGame::query()
            ->where('user_id', $user->id)
            ->where('game_id', $game->id)
            ->with(['game', 'connection'])
            ->get();

        if ($group->isEmpty()) {
            return null;
        }

        $meta = UserGameMeta::where('user_id', $user->id)->where('game_id', $game->id)->first();

        return $this->entry($group, $meta);
    }

    /**
     * @param  Collection<int, OwnedGame>  $group
     * @return array<string, mixed>
     */
    private function entry(Collection $group, ?UserGameMeta $meta): array
    {
        $game = $group->first()->game;
        $knownPlaytimes = $group->pluck('playtime_minutes')->reject(fn ($v) => $v === null);

        return [
            'id' => $game->id,
            'igdb_id' => $game->igdb_id,
            'title' => $game->title,
            'cover_url' => $game->cover_url,
            'genres' => $game->genres ?? [],
            'themes' => $game->themes ?? [],
            'keywords' => $game->keywords ?? [],
            'game_modes' => $game->game_modes ?? [],
            'release_date' => $game->release_date?->toDateString(),
            'time_to_beat_minutes' => $game->time_to_beat_minutes,
            // T27/V33: null = unrated | non-ESRB-market, never a placeholder string.
            'esrb_rating' => $game->esrb_rating,
            // T27/V32: null = not yet fetched, distinct from false.
            'multiplayer' => $game->multiplayer,
            'coop' => $game->coop,
            'local_multiplayer' => $game->local_multiplayer,
            'local_coop' => $game->local_coop,
            // No meta row means untouched — status defaults to unplayed.
            'status' => $meta?->status->value ?? 'unplayed',
            // V12: only a real meta row counts as user-declared for the
            // unplayed collection; the default above never does.
            'status_declared' => $meta !== null,
            'tags' => $meta?->tags ?? [],
            'notes' => $meta?->notes,
            'rating' => $meta?->rating,
            // V28: no meta row means never hidden.
            'hidden' => $meta?->hidden ?? false,
            'platforms' => $group->map(fn (OwnedGame $owned) => [
                'platform' => $owned->connection->platform,
                // V13: disconnected status flows through for UI badges.
                'connection_status' => $owned->connection->status->value,
                'playtime_minutes' => $owned->playtime_minutes,
                'last_played_at' => $owned->last_played_at?->toIso8601String(),
                // T26/V31: Steam-only, null = never successfully checked.
                'deck_status' => $owned->deck_status?->value,
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
            // V28: hidden games excluded by default; include_hidden=1 reveals them.
            ->when(empty($filters['include_hidden']), fn (Collection $c) => $c->filter(
                fn (array $e) => ! $e['hidden'],
            ))
            // T28: multi-select via the same comma-string-or-array
            // convention as `tags` (valueList) — a single value behaves
            // exactly as before (backward compatible with T22 callers and
            // saved custom-collection filter JSON, T23).
            ->when(isset($filters['platform']), fn (Collection $c) => $c->filter(
                fn (array $e) => array_intersect(
                    $this->valueList($filters['platform']),
                    array_column($e['platforms'], 'platform'),
                ) !== [],
            ))
            ->when(isset($filters['genre']), fn (Collection $c) => $c->filter(
                fn (array $e) => array_intersect($this->valueList($filters['genre']), $e['genres']) !== [],
            ))
            ->when(isset($filters['theme']), fn (Collection $c) => $c->filter(
                fn (array $e) => array_intersect($this->valueList($filters['theme']), $e['themes']) !== [],
            ))
            ->when(isset($filters['keyword']), fn (Collection $c) => $c->filter(
                fn (array $e) => array_intersect($this->valueList($filters['keyword']), $e['keywords']) !== [],
            ))
            ->when(isset($filters['game_mode']), fn (Collection $c) => $c->filter(
                fn (array $e) => array_intersect($this->valueList($filters['game_mode']), $e['game_modes']) !== [],
            ))
            // T28: title substring, case-insensitive.
            ->when(! empty($filters['q']), fn (Collection $c) => $c->filter(
                fn (array $e) => str_contains(mb_strtolower($e['title']), mb_strtolower(trim($filters['q']))),
            ))
            // T26/V31: matches any owning platform row whose deck_status is
            // in the selected set; null (never checked) never matches.
            ->when(isset($filters['deck_status']), fn (Collection $c) => $c->filter(
                fn (array $e) => array_intersect(
                    $filters['deck_status'],
                    array_filter(array_column($e['platforms'], 'deck_status')),
                ) !== [],
            ))
            ->when(isset($filters['esrb']), fn (Collection $c) => $c->filter(
                fn (array $e) => $e['esrb_rating'] === $filters['esrb'],
            ))
            // T27/V32: equality against the derived flag; null (best-effort
            // miss) matches neither an explicit true nor false query.
            ->when(isset($filters['multiplayer']), fn (Collection $c) => $c->filter(
                fn (array $e) => $e['multiplayer'] === $filters['multiplayer'],
            ))
            ->when(isset($filters['coop']), fn (Collection $c) => $c->filter(
                fn (array $e) => $e['coop'] === $filters['coop'],
            ))
            ->when(isset($filters['local_multiplayer']), fn (Collection $c) => $c->filter(
                fn (array $e) => $e['local_multiplayer'] === $filters['local_multiplayer'],
            ))
            ->when(isset($filters['local_coop']), fn (Collection $c) => $c->filter(
                fn (array $e) => $e['local_coop'] === $filters['local_coop'],
            ))
            // V29: manual collection membership, synthesized internally by
            // LibraryController::resolveCollection — never a public param.
            ->when(isset($filters['game_ids']), fn (Collection $c) => $c->filter(
                fn (array $e) => in_array($e['id'], $filters['game_ids'], true),
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
            ))
            ->when(isset($filters['status']), fn (Collection $c) => $c->filter(
                fn (array $e) => $e['status'] === $filters['status'],
            ))
            ->when(isset($filters['tags']), fn (Collection $c) => $c->filter(
                fn (array $e) => array_intersect($this->valueList($filters['tags']), $e['tags']) !== [],
            ))
            ->when(isset($filters['collection']), fn (Collection $c) => $this->systemCollection(
                $c,
                $filters['collection'],
            ));
    }

    /**
     * @return list<string>
     */
    private function valueList(string|array $value): array
    {
        return is_array($value) ? array_values($value) : array_map('trim', explode(',', $value));
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $entries
     * @return Collection<int, array<string, mixed>>
     */
    private function systemCollection(Collection $entries, string $slug): Collection
    {
        return match ($slug) {
            // V12: known-zero playtime or user-declared unplayed; null
            // playtime alone stays out.
            'unplayed' => $entries->filter(
                fn (array $e) => $e['total_playtime_minutes'] === 0
                    || ($e['status_declared'] && $e['status'] === 'unplayed'),
            ),
            // I.api: played, untouched 6+ months, not finished.
            'abandoned' => $entries->filter(
                fn (array $e) => $e['last_played_at'] !== null
                    && Date::parse($e['last_played_at'])->lte(Date::now()->subMonths(SystemCollections::ABANDONED_AFTER_MONTHS))
                    && $e['status'] !== 'finished',
            ),
            // §C: conditional on time-to-beat data being present.
            'quick_wins' => $entries->filter(
                fn (array $e) => $e['time_to_beat_minutes'] !== null
                    && $e['time_to_beat_minutes'] < SystemCollections::QUICK_WIN_MAX_MINUTES,
            ),
        };
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
