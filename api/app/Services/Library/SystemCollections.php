<?php

namespace App\Services\Library;

class SystemCollections
{
    /**
     * §C: quick wins threshold — estimated completion under 5 hours.
     */
    public const QUICK_WIN_MAX_MINUTES = 300;

    public const ABANDONED_AFTER_MONTHS = 6;

    /**
     * T71: "My Favorites" — 4 and 5-star personal ratings.
     */
    public const FAVORITES_MIN_RATING = 4;

    /**
     * T71/V69: "Achievement Hunt" — 50%+ unlocked. Games with total=0
     * (achievement-capable but genuinely no achievements) are guarded out
     * elsewhere, never treated as 0/0 = 100%.
     */
    public const ACHIEVEMENT_HUNT_MIN_RATIO = 0.5;

    /**
     * I.api system collection presets. Filter semantics live in
     * LibraryQuery; this is the catalogue the API advertises.
     *
     * @return list<array{slug: string, name: string, description: string}>
     */
    public static function all(): array
    {
        return [
            [
                'slug' => 'unplayed',
                'name' => 'Unplayed',
                'description' => 'Games with zero recorded playtime or marked unplayed.',
            ],
            [
                'slug' => 'abandoned',
                'name' => 'Abandoned',
                'description' => 'Played, untouched for six months or more, and not finished.',
            ],
            [
                'slug' => 'quick_wins',
                'name' => 'Quick wins',
                'description' => 'Beatable in under five hours, where completion data exists.',
            ],
            [
                'slug' => 'favorites',
                'name' => 'My Favorites',
                'description' => 'Rated 4 or 5 stars.',
            ],
            [
                'slug' => 'achievement_hunt',
                'name' => 'Achievement Hunt',
                'description' => 'Games with 50% or more achievements unlocked.',
            ],
        ];
    }

    /**
     * @return list<string>
     */
    public static function slugs(): array
    {
        return array_column(self::all(), 'slug');
    }
}
