<?php

declare(strict_types=1);

namespace Tests\Connectors\Unit\WaSender;

use Kanvas\Connectors\WaSender\Actions\DownloadMessageFileAction;
use Tests\TestCase;

/**
 * WhatsApp hands us a mimetype, never a bare kind. Matching on 'image'/'video' meant every real
 * payload fell through to `bin`, the file was stored as `*.bin`, and WordPress — which judges an
 * upload by its filename — refused every one with "no tienes permisos para subir este tipo de
 * archivo". Articles published with no featured image as a result.
 */
final class DownloadMessageFileExtensionTest extends TestCase
{
    public function testAMimetypeResolvesToItsRealExtension(): void
    {
        $this->assertSame('jpg', DownloadMessageFileAction::fileExtensionFor('image/jpeg'));
        $this->assertSame('png', DownloadMessageFileAction::fileExtensionFor('image/png'));
        $this->assertSame('webp', DownloadMessageFileAction::fileExtensionFor('image/webp'));
        $this->assertSame('mp4', DownloadMessageFileAction::fileExtensionFor('video/mp4'));
        $this->assertSame('pdf', DownloadMessageFileAction::fileExtensionFor('application/pdf'));
    }

    public function testParametersAndCasingDoNotDefeatTheLookup(): void
    {
        $this->assertSame('jpg', DownloadMessageFileAction::fileExtensionFor('IMAGE/JPEG'));
        $this->assertSame('png', DownloadMessageFileAction::fileExtensionFor('image/png; charset=binary'));
    }

    /**
     * An unmapped subtype still lands on something WordPress accepts for its family, rather than
     * the `.bin` that started this.
     */
    public function testAnUnmappedSubtypeFallsBackToItsFamily(): void
    {
        $this->assertSame('jpg', DownloadMessageFileAction::fileExtensionFor('image/x-something-new'));
        $this->assertSame('mp4', DownloadMessageFileAction::fileExtensionFor('video/quicktime'));
        $this->assertSame('bin', DownloadMessageFileAction::fileExtensionFor('application/octet-stream'));
    }
}
