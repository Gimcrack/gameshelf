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
