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
}
