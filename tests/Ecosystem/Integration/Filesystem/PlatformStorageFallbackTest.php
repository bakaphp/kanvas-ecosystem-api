<?php

declare(strict_types=1);

namespace Tests\Ecosystem\Integration\Filesystem;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Kanvas\Apps\Models\Apps;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Filesystem\Services\FilesystemServices;
use Tests\TestCase;

/**
 * A self-serve app created by startOnboarding has no S3 settings of its own. Instead of failing its
 * first upload it uses the platform disk from .env, under a per-app prefix, and nothing is copied
 * into the app's settings where an admin could read it.
 */
final class PlatformStorageFallbackTest extends TestCase
{
    use DatabaseTransactions;

    public function testAppWithoutStorageSettingsUploadsToThePlatformDiskUnderItsOwnPrefix(): void
    {
        Storage::fake('s3');
        $app = $this->appWithoutStorageSettings();

        $filesystem = new FilesystemServices($app)->upload(UploadedFile::fake()->image('logo.jpg'), Auth::user());

        $prefix = 'apps/' . $app->getId();
        $this->assertCount(1, Storage::disk('s3')->allFiles($prefix));
        $this->assertStringContainsString($prefix . '/', $filesystem->path);
        $this->assertNull($app->get('service-account-file'));
        $this->assertNull($app->get('cloud-bucket'));
    }

    public function testAppWithItsOwnStorageSettingsDoesNotUseThePlatformDisk(): void
    {
        Storage::fake('s3');

        new FilesystemServices(app(Apps::class))->upload(UploadedFile::fake()->image('logo.jpg'), Auth::user());

        $this->assertSame([], Storage::disk('s3')->allFiles());
    }

    public function testMissingPlatformCredentialsStillFailLoudly(): void
    {
        config(['filesystems.disks.s3.key' => null]);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Missing AWS credentials');

        new FilesystemServices($this->appWithoutStorageSettings());
    }

    private function appWithoutStorageSettings(): Apps
    {
        $slug = 'self-serve-' . Str::uuid();
        $app = new Apps();
        $app->fill([
            'name' => $slug,
            'url' => 'https://' . $slug . '.test',
            'description' => 'Created by onboarding',
            'domain' => $slug . '.test',
            'is_actived' => 1,
            'ecosystem_auth' => 0,
            'payments_active' => 0,
            'is_public' => 1,
            'domain_based' => 0,
        ]);
        $app->saveOrFail();

        return $app;
    }
}
