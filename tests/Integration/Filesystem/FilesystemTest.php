<?php
declare(strict_types=1);

namespace Tests\Integration\SystemModules;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Kanvas\Filesystem\Actions\AttachFilesystemAction;
use Kanvas\Filesystem\Actions\UploadFileAction;
use Kanvas\Filesystem\Models\Filesystem;
use Kanvas\Filesystem\Models\FilesystemEntities;
use Tests\TestCase;

final class FilesystemTest extends TestCase
{
    public function testCreateFilesystem()
    {
        $file = UploadedFile::fake()->image('avatar.jpg');

        $uploadFile = new UploadFileAction(Auth::user());

        $this->assertInstanceOf(Filesystem::class, $uploadFile->execute($file));
    }

    public function testAttachedFileToEntity()
    {
        $file = UploadedFile::fake()->image('avatar.jpg');

        $uploadFile = new UploadFileAction(Auth::user());
        $fileSystem = $uploadFile->execute($file);

        $attachFileSystem = new AttachFilesystemAction($fileSystem, Auth::user());
        $fieldName = 'avatar';
        $filesystemEntities = $attachFileSystem->execute('avatar');
        $this->assertInstanceOf(FilesystemEntities::class, $filesystemEntities);
        $this->assertEquals($fieldName, $filesystemEntities->field_name);
    }

    public function testGetFiles()
    {
        $file = UploadedFile::fake()->image('avatar.jpg');
        $user = Auth::user();
        $user->attach(
            (new UploadFileAction($user))->execute($file),
            'avatar'
        );

        $this->assertGreaterThan(0, $user->getFiles()->count());
        $this->assertGreaterThan(0, $user->getFiles()->first()->delete());
    }

    public function testDeleteFiles()
    {
        $file = UploadedFile::fake()->image('avatar.jpg');
        $user = Auth::user();
        $user->attach(
            (new UploadFileAction($user))->execute($file),
            'avatar'
        );

        $this->assertGreaterThan(0, $user->deleteFiles());
    }

    public function testAttachedFileViaUrl()
    {
        $url = 'https://www.google.com/images/branding/googlelogo/1x/googlelogo_color_272x92dp.png';

        $user = Auth::user();
        $user->attachUrl($url, 'newLogo');

        $this->assertGreaterThan(0, $user->getFiles()->count());
        $this->assertGreaterThan(0, $user->getFiles()->first()->delete());
    }
}
