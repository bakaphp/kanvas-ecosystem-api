<?php

declare(strict_types=1);

namespace Tests\Ecosystem\Integration\Filesystem;

use Illuminate\Contracts\Filesystem\Filesystem as Disk;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Kanvas\Apps\Models\Apps;
use Kanvas\Filesystem\Models\Filesystem;
use Kanvas\Filesystem\Services\FilesystemServices;
use Mockery;
use ReflectionProperty;
use RuntimeException;
use Tests\TestCase;

/**
 * The disk is built without `throw`, so a failed write returns false. Saving the row anyway left a file whose url was
 * the bucket root: an agent handed that file reported it unreadable and nobody saw the storage error.
 */
final class FailedStorageWriteTest extends TestCase
{
    public function test_a_write_the_storage_refuses_fails_loudly_and_saves_no_row(): void
    {
        $services = new FilesystemServices(app(Apps::class));
        $disk = Mockery::mock(Disk::class);
        $disk->shouldReceive('exists')->andReturn(false);
        $disk->shouldReceive('put')->andReturn(false);
        $disk->shouldReceive('putFileAs')->andReturn(false);
        new ReflectionProperty($services, 'storage')->setValue($services, $disk);

        $name = 'failed-write-' . uniqid() . '.pdf';

        try {
            $services->upload(UploadedFile::fake()->create($name, 10, 'application/pdf'), Auth::user());
            $this->fail('A refused write must not produce a file.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('could not be written to storage', $e->getMessage());
        }

        $this->assertFalse(Filesystem::query()->where('name', $name)->exists());
    }
}
