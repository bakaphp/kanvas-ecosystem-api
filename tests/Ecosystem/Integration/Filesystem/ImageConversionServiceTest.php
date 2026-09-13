<?php

declare(strict_types=1);

namespace Tests\Ecosystem\Integration\Filesystem;

use Baka\Support\TempFile;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Kanvas\Apps\Models\Apps;
use Kanvas\Filesystem\Services\FilesystemServices;
use Kanvas\Filesystem\Services\ImageConversionService;
use RuntimeException;
use Tests\TestCase;

class ImageConversionServiceTest extends TestCase
{
    /**
     * An IP-literal public host skips SafeUrl's DNS lookup, so the faked response is all the test needs.
     */
    private const string IMAGE_HOST = 'https://93.184.216.34';

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

    public function testConvertImageFromUrlDeletesDownloadedSource(): void
    {
        $this->fakeImageDownload();

        $before = $this->tempFiles('png');
        $convertedPath = ImageConversionService::convertImageFromUrl(self::IMAGE_HOST . '/' . uniqid('source-') . '.png', 'jpg');

        try {
            $this->assertFileExists($convertedPath);
            $this->assertStringEndsWith('.jpg', $convertedPath);
            $this->assertSame([], array_diff($this->tempFiles('png'), $before), 'the downloaded source must not outlive the conversion');
        } finally {
            TempFile::delete($convertedPath);
        }
    }

    public function testConvertImageFromUrlDeletesDownloadedSourceWhenFormatIsUnsupported(): void
    {
        Http::fake([self::IMAGE_HOST . '/*' => Http::response('<svg xmlns="http://www.w3.org/2000/svg"/>', 200, ['Content-Type' => 'image/svg+xml'])]);

        $before = $this->tempFiles('svg');

        try {
            ImageConversionService::convertImageFromUrl(self::IMAGE_HOST . '/' . uniqid() . '.svg', 'jpg');
            $this->fail('Unsupported source format should throw');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Unsupported source format', $e->getMessage());
        }

        $this->assertSame([], array_diff($this->tempFiles('svg'), $before));
    }

    /**
     * convertFilesystem() uploads whatever path comes back, so deleting the download here would
     * break every same-format conversion.
     */
    public function testConvertImageFromUrlKeepsSourceWhenAlreadyInTargetFormat(): void
    {
        $this->fakeImageDownload();

        $path = ImageConversionService::convertImageFromUrl(self::IMAGE_HOST . '/' . uniqid('source-') . '.png', 'png');

        try {
            $this->assertFileExists($path);
            $this->assertStringEndsWith('.png', $path);
        } finally {
            TempFile::delete($path);
        }
    }

    public function testConvertFilesystemReplacesImageWithConvertedFormatAndLeavesNoTempFiles(): void
    {
        $this->assertConvertFilesystemCleansUp('jpg');
    }

    public function testConvertFilesystemToSameFormatUploadsAndLeavesNoTempFiles(): void
    {
        $this->assertConvertFilesystemCleansUp('png');
    }

    private function assertConvertFilesystemCleansUp(string $targetFormat): void
    {
        $app = app(Apps::class);
        $filesystemService = new FilesystemServices($app);
        $this->fakeImageDownload();

        $filesystem = $filesystemService->upload(UploadedFile::fake()->image('source.png', 40, 40), Auth::user());
        $filesystem->forceFill(['url' => self::IMAGE_HOST . '/' . uniqid('source-') . '.png'])->saveOrFail();

        $before = [...$this->tempFiles('png'), ...$this->tempFiles('jpg')];

        $converted = ImageConversionService::convertFilesystem($filesystem, $targetFormat);

        try {
            $this->assertSame($targetFormat, $converted->file_type);
            $this->assertStringEndsWith('.' . $targetFormat, $converted->name);
            $this->assertNotSame(self::IMAGE_HOST, substr((string) $converted->url, 0, strlen(self::IMAGE_HOST)));
            $this->assertSame([], array_diff([...$this->tempFiles('png'), ...$this->tempFiles('jpg')], $before));
        } finally {
            $filesystemService->delete($converted);
        }
    }

    private function fakeImageDownload(): void
    {
        Http::fake([self::IMAGE_HOST . '/*' => Http::response(UploadedFile::fake()->image('source.png', 40, 40)->getContent(), 200, ['Content-Type' => 'image/png'])]);
    }

    /**
     * @return list<string>
     */
    private function tempFiles(string $extension): array
    {
        return glob(storage_path('app/temp') . '/*.' . $extension) ?: [];
    }
}
