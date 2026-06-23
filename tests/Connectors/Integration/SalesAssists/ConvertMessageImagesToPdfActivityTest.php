<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\SalesAssists;

use Kanvas\Filesystem\Services\FilesystemServices;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ConvertMessageImagesToPdfActivityTest extends TestCase
{
    #[DataProvider('urlExtensionProvider')]
    public function testGetExtensionFromUrlDetectsRealExtension(string $url, string $expected): void
    {
        $this->assertSame($expected, FilesystemServices::getExtensionFromUrl($url));
    }

    public static function urlExtensionProvider(): array
    {
        return [
            // The incident: a HEIC-named file whose stored URL is actually a PDF —
            // must resolve to 'pdf' so the file-sharing branch skips it instead of
            // embedding a PDF as an <img>.
            'pdf url with heic name' => ['https://cdn.salesassist.io/ad9rlNfw4XbiLCKXFcNaVnaGD1V4b3AaTaTDgpPz.pdf', 'pdf'],
            'heic url' => ['https://cdn.salesassist.io/IMG_9794.heic', 'heic'],
            'jpg with query string' => ['https://cdn.salesassist.io/photo.jpg?v=123&token=abc', 'jpg'],
            'uppercase extension normalized' => ['https://cdn.salesassist.io/IMG.HEIC', 'heic'],
            // A "pdf" only in the path segment must NOT be mistaken for the extension.
            'pdf-in-path-not-extension' => ['https://cdn.salesassist.io/pdf-archive/photo.heic', 'heic'],
            'no extension' => ['https://cdn.salesassist.io/file', ''],
        ];
    }
}
