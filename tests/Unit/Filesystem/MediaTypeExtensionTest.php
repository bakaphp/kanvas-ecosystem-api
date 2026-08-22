<?php

declare(strict_types=1);

namespace Tests\Unit\Filesystem;

use Kanvas\Filesystem\Enums\MediaTypeEnum;
use Tests\TestCase;

/**
 * WhatsApp hands us a mimetype, never a bare kind, and the old per-connector `match` on
 * 'image'/'video' fell through to `bin` for every real payload. Files were stored as `*.bin` and
 * WordPress — which judges an upload by its filename — refused each one with "no tienes permisos
 * para subir este tipo de archivo", so articles published with no featured image.
 */
final class MediaTypeExtensionTest extends TestCase
{
    public function testAMimetypeResolvesToItsRealExtension(): void
    {
        $this->assertSame('jpg', MediaTypeEnum::extensionForMime('image/jpeg'));
        $this->assertSame('png', MediaTypeEnum::extensionForMime('image/png'));
        $this->assertSame('webp', MediaTypeEnum::extensionForMime('image/webp'));
        $this->assertSame('mp4', MediaTypeEnum::extensionForMime('video/mp4'));
        $this->assertSame('pdf', MediaTypeEnum::extensionForMime('application/pdf'));
    }

    public function testParametersAndCasingDoNotDefeatTheLookup(): void
    {
        $this->assertSame('jpg', MediaTypeEnum::extensionForMime('IMAGE/JPEG'));
        $this->assertSame('png', MediaTypeEnum::extensionForMime('image/png; charset=binary'));
    }

    /**
     * An unmapped subtype still lands on something WordPress accepts for its family, rather than
     * the `.bin` that started this.
     */
    public function testAnUnmappedSubtypeFallsBackToItsFamily(): void
    {
        $this->assertSame('jpg', MediaTypeEnum::extensionForMime('image/x-something-new'));
        $this->assertSame('mp4', MediaTypeEnum::extensionForMime('video/quicktime'));
        $this->assertSame('bin', MediaTypeEnum::extensionForMime('application/octet-stream'));
    }
}
