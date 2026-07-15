<?php

namespace App\Services\Igdb;

use Illuminate\Support\Facades\Date;

/**
 * One IGDB record → `games` column mapping, shared by the sync-time
 * matcher and manual/wishlist adds so both write identical shapes (V7).
 */
class IgdbGameAttributes
{
    // B7/V37: IGDB age_rating_organizations.id — ESRB only, PEGI/CERO/etc
    // excluded. `rating_category.rating` (nested) gives the label string
    // directly ("M", "E10+"...) — no local id→label table needed.
    private const ESRB_ORGANIZATION = 1;

    /**
     * @param  array<string, mixed>  $igdb
     * @return array<string, mixed>
     */
    public static function fromRecord(array $igdb): array
    {
        return [
            'igdb_id' => $igdb['id'],
            'title' => $igdb['name'],
            'cover_url' => $igdb['cover']['url'] ?? null,
            'genres' => array_map(
                fn (array $genre) => $genre['name'],
                $igdb['genres'] ?? [],
            ),
            'themes' => array_map(
                fn (array $theme) => $theme['name'],
                $igdb['themes'] ?? [],
            ),
            'keywords' => array_map(
                fn (array $keyword) => $keyword['name'],
                $igdb['keywords'] ?? [],
            ),
            'game_modes' => array_map(
                fn (array $mode) => $mode['name'],
                $igdb['game_modes'] ?? [],
            ),
            'release_date' => isset($igdb['first_release_date'])
                ? Date::createFromTimestamp($igdb['first_release_date'])->toDateString()
                : null,
            'esrb_rating' => self::esrbRating($igdb['age_ratings'] ?? []),
            ...self::multiplayerFlags($igdb['multiplayer_modes'] ?? []),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $ageRatings
     */
    private static function esrbRating(array $ageRatings): ?string
    {
        foreach ($ageRatings as $rating) {
            if (($rating['organization'] ?? null) === self::ESRB_ORGANIZATION) {
                return $rating['rating_category']['rating'] ?? null;
            }
        }

        return null;
    }

    /**
     * V32: unified source, OR'd across every returned row. `local_coop`
     * means offlinecoop or lancoop specifically — splitscreen alone could be
     * local competitive, not coop. A successful call with no rows means IGDB
     * has no multiplayer data for this game, i.e. false (mirrors genres);
     * a row simply never being fetched (pre-T27 data) is what stays null.
     *
     * @param  list<array<string, mixed>>  $modes
     * @return array{multiplayer: bool, coop: bool, local_multiplayer: bool, local_coop: bool}
     */
    private static function multiplayerFlags(array $modes): array
    {
        $any = fn (string $field) => array_filter(
            $modes,
            fn (array $mode) => (bool) ($mode[$field] ?? false),
        ) !== [];
        $anyOver1 = fn (string $field) => array_filter(
            $modes,
            fn (array $mode) => (int) ($mode[$field] ?? 0) > 1,
        ) !== [];

        $localCoop = $any('offlinecoop') || $any('lancoop');
        $localMultiplayer = $localCoop || $any('splitscreen') || $anyOver1('offlinemax');
        $coop = $localCoop || $any('onlinecoop') || $any('campaigncoop') || $any('dropin');
        $multiplayer = $coop || $localMultiplayer || $any('splitscreenonline') || $anyOver1('onlinemax');

        return [
            'multiplayer' => $multiplayer,
            'coop' => $coop,
            'local_multiplayer' => $localMultiplayer,
            'local_coop' => $localCoop,
        ];
    }
}
