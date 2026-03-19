<?php

declare(strict_types=1);

namespace Tests\Ecosystem\Integration\Filesystem;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Kanvas\Apps\Models\Apps;
use Kanvas\Filesystem\Actions\AttachFilesystemAction;
use Kanvas\Filesystem\Models\Filesystem;
use Kanvas\Filesystem\Models\FilesystemEntities;
use Kanvas\Filesystem\Services\FilesystemServices;
use Kanvas\Filesystem\Services\ImageOptimizerService;
use Tests\TestCase;

final class FilesystemTest extends TestCase
{
    public function testCreateFilesystem()
    {
        $file = UploadedFile::fake()->image('avatar.jpg');
        $filesystem = new FilesystemServices(app(Apps::class));

        $this->assertInstanceOf(Filesystem::class, $filesystem->upload($file, Auth::user()));
    }

    public function testAttachedFileToEntity()
    {
        $file = UploadedFile::fake()->image('avatar.jpg');

        $filesystemService = new FilesystemServices(app(Apps::class));

        $fileSystem = $filesystemService->upload($file, Auth::user());

        $attachFileSystem = new AttachFilesystemAction($fileSystem, Auth::user());
        $fieldName = 'avatar';
        $filesystemEntities = $attachFileSystem->execute('avatar');
        $this->assertInstanceOf(FilesystemEntities::class, $filesystemEntities);
        $this->assertEquals($fieldName, $filesystemEntities->field_name);
    }

    public function testGetFiles()
    {
        $file = UploadedFile::fake()->image('avatar.jpg');
        $filesystem = new FilesystemServices(app(Apps::class));
        $user = Auth::user();

        $user->addFile(
            $filesystem->upload($file, $user),
            'avatar'
        );

        $this->assertGreaterThan(0, $user->getFiles()->count());
        $this->assertGreaterThan(0, $user->getFiles()->first()->delete());
    }

    public function testDeleteFiles()
    {
        $file = UploadedFile::fake()->image('avatar.jpg');
        $filesystemService = new FilesystemServices(app(Apps::class));
        $user = Auth::user();
        $uploadedFile = $filesystemService->upload($file, $user);

        $user->addFile(
            $uploadedFile,
            'avatar'
        );

        $this->assertGreaterThan(0, $user->deleteFiles());
        $this->assertTrue($filesystemService->delete($uploadedFile));
    }

    public function testAttachedFileViaUrl()
    {
        $url = 'https://www.google.com/images/branding/googlelogo/1x/googlelogo_color_272x92dp.png';

        $user = Auth::user();
        $user->addFileFromUrl($url, 'newLogo');

        $this->assertGreaterThan(0, $user->getFiles()->count());
        $this->assertGreaterThan(0, $user->getFiles()->first()->delete());
    }

    public function testUploadFileFromUrl()
    {
        $url = 'https://www.google.com/images/branding/googlelogo/1x/googlelogo_color_272x92dp.png';

        $filesystemService = new FilesystemServices(app(Apps::class));
        $user = Auth::user();

        $filesystem = $filesystemService->uploadFileFromUrl($url, $user);

        $this->assertInstanceOf(Filesystem::class, $filesystem);
        $this->assertNotEmpty($filesystem->url);
        $this->assertNotEmpty($filesystem->path);
        $this->assertStringContainsString('.png', $filesystem->name);

        // Clean up
        $this->assertTrue($filesystemService->delete($filesystem));
    }

    public function testUploadFileFromUrlWithoutExtension()
    {
        // URL without clear file extension
        $url = 'https://picsum.photos/200/300';

        $filesystemService = new FilesystemServices(app(Apps::class));
        $user = Auth::user();

        $filesystem = $filesystemService->uploadFileFromUrl($url, $user);

        $this->assertInstanceOf(Filesystem::class, $filesystem);
        $this->assertNotEmpty($filesystem->url);
        $this->assertNotEmpty($filesystem->path);

        // Clean up
        $filesystemService->delete($filesystem);
    }

    public function testUploadFileFromUrlInvalidUrl()
    {
        $url = 'https://invalid-url-that-does-not-exist-12345.com/file.jpg';

        $filesystemService = new FilesystemServices(app(Apps::class));
        $user = Auth::user();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Failed to download file from URL');

        $filesystemService->uploadFileFromUrl($url, $user);
    }

    public function testUploadFileFromUrlDifferentFileTypes()
    {
        $urls = [
            'https://www.w3.org/WAI/ER/tests/xhtml/testfiles/resources/pdf/dummy.pdf',
            'https://filesamples.com/samples/document/txt/sample3.txt',
        ];

        $filesystemService = new FilesystemServices(app(Apps::class));
        $user = Auth::user();

        foreach ($urls as $url) {
            try {
                $filesystem = $filesystemService->uploadFileFromUrl($url, $user);

                $this->assertInstanceOf(Filesystem::class, $filesystem);
                $this->assertNotEmpty($filesystem->url);

                // Clean up
                $filesystemService->delete($filesystem);
            } catch (\Exception $e) {
                // Skip if URL is not accessible during testing
                $this->markTestSkipped('URL not accessible: ' . $url);
            }
        }
    }

    public function testOptimizeLocalFile(): void
    {
        // Create a real large JPEG via GD so optimization has something to compress
        $tempPath = sys_get_temp_dir() . '/test-optimize-' . uniqid() . '.jpg';
        $img = imagecreatetruecolor(2000, 2000);
        $red = imagecolorallocate($img, 255, 0, 0);
        imagefill($img, 0, 0, $red);
        imagejpeg($img, $tempPath, 100);
        imagedestroy($img);

        $originalSize = filesize($tempPath);
        $this->assertGreaterThan(10000, $originalSize, 'Test image should be large enough to optimize');

        $result = ImageOptimizerService::optimizeLocalFile(
            filePath: $tempPath,
            optimize: true,
            maxWidth: 800,
            maxHeight: 800,
            quality: 75,
        );

        $this->assertEquals($tempPath, $result);
        $this->assertFileExists($result);

        $optimizedSize = filesize($result);
        $this->assertLessThan($originalSize, $optimizedSize, 'Optimized file should be smaller than original');

        @unlink($tempPath);
    }

    public function testOptimizeLocalFileWithTempPathNoExtension(): void
    {
        // Simulates UploadedFile::getRealPath() which returns /tmp/phpXXXXXX (no extension)
        $tempPath = sys_get_temp_dir() . '/phptest' . uniqid();
        $img = imagecreatetruecolor(2000, 2000);
        $red = imagecolorallocate($img, 255, 0, 0);
        imagefill($img, 0, 0, $red);
        imagejpeg($img, $tempPath, 100);
        imagedestroy($img);

        $originalSize = filesize($tempPath);
        $this->assertGreaterThan(10000, $originalSize);

        $result = ImageOptimizerService::optimizeLocalFile(
            filePath: $tempPath,
            optimize: true,
            maxWidth: 800,
            maxHeight: 800,
            quality: 75,
        );

        $this->assertEquals($tempPath, $result);
        $this->assertFileExists($result);

        $optimizedSize = filesize($result);
        $this->assertLessThan($originalSize, $optimizedSize, 'File without extension should still be optimized via MIME detection');

        @unlink($tempPath);
    }

    public function testOptimizeLocalFileSkipsNonImageFiles(): void
    {
        $file = UploadedFile::fake()->create('document.pdf', 100, 'application/pdf');
        $filePath = $file->getRealPath();
        $originalSize = filesize($filePath);

        $result = ImageOptimizerService::optimizeLocalFile(
            filePath: $filePath,
            optimize: true,
            maxWidth: 800,
        );

        $this->assertEquals($filePath, $result);
        $this->assertEquals($originalSize, filesize($result));
    }

    public function testUploadWithOptimizationEnabled(): void
    {
        $app = app(Apps::class);
        $app->set('filesystem-optimize-on-upload', true);
        $app->set('filesystem-optimize-max-width', 800);
        $app->set('filesystem-optimize-max-height', 800);
        $app->set('filesystem-optimize-quality', 75);

        // Create a real large JPEG so optimization has something to compress
        $tempPath = sys_get_temp_dir() . '/optimized-upload-' . uniqid() . '.jpg';
        $img = imagecreatetruecolor(2000, 2000);
        $red = imagecolorallocate($img, 255, 0, 0);
        imagefill($img, 0, 0, $red);
        imagejpeg($img, $tempPath, 100);
        imagedestroy($img);

        $originalSize = filesize($tempPath);
        $this->assertGreaterThan(10000, $originalSize);

        $file = new UploadedFile($tempPath, 'optimized-upload.jpg', 'image/jpeg', null, true);
        $filesystemService = new FilesystemServices($app);
        $user = Auth::user();

        $filesystem = $filesystemService->upload($file, $user);

        $this->assertInstanceOf(Filesystem::class, $filesystem);
        $this->assertNotEmpty($filesystem->url);
        $this->assertLessThan($originalSize, $filesystem->size, 'Optimized upload should be smaller than original');

        // Clean up
        $app->set('filesystem-optimize-on-upload', false);
        $filesystemService->delete($filesystem);
        @unlink($tempPath);
    }

    public function testUploadWithOptimizationDisabled(): void
    {
        $app = app(Apps::class);
        $app->set('filesystem-optimize-on-upload', false);

        $file = UploadedFile::fake()->image('no-optimize.jpg', 2000, 2000);
        $filesystemService = new FilesystemServices($app);
        $user = Auth::user();

        $filesystem = $filesystemService->upload($file, $user);

        $this->assertInstanceOf(Filesystem::class, $filesystem);
        $this->assertNotEmpty($filesystem->url);

        // Clean up
        $filesystemService->delete($filesystem);
    }
}
