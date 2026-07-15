<?php

namespace Tests\Unit;

use App\Services\Igdb\IgdbImageUrl;
use PHPUnit\Framework\TestCase;

class IgdbImageUrlTest extends TestCase
{
    public function test_upsizes_thumb_to_cover_big(): void
    {
        $this->assertSame(
            '//images.igdb.com/igdb/image/upload/t_cover_big/co1rs4.jpg',
            IgdbImageUrl::resize('//images.igdb.com/igdb/image/upload/t_thumb/co1rs4.jpg'),
        );
    }

    public function test_null_stays_null(): void
    {
        $this->assertNull(IgdbImageUrl::resize(null));
    }

    public function test_url_without_size_segment_untouched(): void
    {
        $this->assertSame('//images.igdb.com/cover.jpg', IgdbImageUrl::resize('//images.igdb.com/cover.jpg'));
    }

    public function test_custom_size_honored(): void
    {
        $this->assertSame(
            '//images.igdb.com/igdb/image/upload/t_1080p/co1rs4.jpg',
            IgdbImageUrl::resize('//images.igdb.com/igdb/image/upload/t_thumb/co1rs4.jpg', 't_1080p'),
        );
    }
}
