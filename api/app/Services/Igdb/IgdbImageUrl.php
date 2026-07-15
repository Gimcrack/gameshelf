<?php

namespace App\Services\Igdb;

/**
 * IGDB image URLs embed a size segment (t_thumb ≈ 90x128). Grid cards render
 * covers much larger than that, so every cover URL is upsized here before
 * display rather than stored pre-sized — one place decides display size.
 */
class IgdbImageUrl
{
    private const DEFAULT_SIZE = 't_cover_big';

    public static function resize(?string $url, string $size = self::DEFAULT_SIZE): ?string
    {
        if ($url === null) {
            return null;
        }

        return preg_replace('#/t_[a-z0-9_]+/#', "/{$size}/", $url) ?? $url;
    }
}
