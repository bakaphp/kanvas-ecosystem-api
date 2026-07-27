<?php

declare(strict_types=1);

namespace Tests\Ecosystem\Integration\Filesystem;

use Kanvas\Apps\Models\Apps;
use Kanvas\Filesystem\Services\ImageConversionService;
use Tests\TestCase;

class ImageConversionServiceTest extends TestCase
{
    public function testLeavesViewableImagesUntouched(): void
    {
        $app = app(Apps::class);

        $html = '<div><img src="https://cdn.example.com/photo.jpg" alt="a"><img src="https://cdn.example.com/logo.png"></div>';

        $this->assertSame(
            $html,
            ImageConversionService::convertHtmlImagesToViewable($html, $app),
        );
    }

    public function testLeavesHeicUrlWithoutFilesystemUntouched(): void
    {
        $app = app(Apps::class);

        // No matching Filesystem row and no user to upload one — getViewableUrl falls back to the original URL.
        $html = '<img src="https://cdn.example.com/does-not-exist-' . uniqid() . '.heic">';

        $this->assertSame(
            $html,
            ImageConversionService::convertHtmlImagesToViewable($html, $app),
        );
    }

    public function testLeavesHtmlWithoutImagesUntouched(): void
    {
        $app = app(Apps::class);

        $html = '<p>No images here, just <a href="https://cdn.example.com/x.heic">a link</a>.</p>';

        $this->assertSame(
            $html,
            ImageConversionService::convertHtmlImagesToViewable($html, $app),
        );
    }

    public function testDetectsExtensionsThatNeedConversion(): void
    {
        $this->assertTrue(ImageConversionService::needsConversion('heic'));
        $this->assertTrue(ImageConversionService::needsConversion('HEIF'));
        $this->assertTrue(ImageConversionService::needsConversion('tiff'));
        $this->assertFalse(ImageConversionService::needsConversion('jpg'));
        $this->assertFalse(ImageConversionService::needsConversion('png'));
    }
}
