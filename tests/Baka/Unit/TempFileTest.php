<?php

declare(strict_types=1);

namespace Tests\Baka\Unit;

use Baka\Support\TempFile;
use RuntimeException;
use Tests\TestCase;

final class TempFileTest extends TestCase
{
    public function testPathIsUniqueAndDoesNotCreateTheFile(): void
    {
        $first = TempFile::path('pdf');
        $second = TempFile::path('.pdf');

        $this->assertNotSame($first, $second);
        $this->assertStringStartsWith(sys_get_temp_dir() . '/' . TempFile::PREFIX, $first);
        $this->assertStringEndsWith('.pdf', $first);
        $this->assertStringEndsWith('.pdf', $second);
        $this->assertStringNotContainsString('..pdf', $second);
        $this->assertFileDoesNotExist($first);
    }

    public function testPathWithoutExtensionHasNoTrailingDot(): void
    {
        $this->assertStringEndsNotWith('.', TempFile::path());
    }

    public function testPathHonoursCustomDirectory(): void
    {
        $directory = sys_get_temp_dir() . '/';

        $this->assertStringStartsWith(sys_get_temp_dir() . '/' . TempFile::PREFIX, TempFile::path('png', directory: $directory));
    }

    public function testUsingReturnsCallbackResultAndDeletesTheFile(): void
    {
        $seenPath = null;

        $result = TempFile::using(
            function (string $path) use (&$seenPath): string {
                file_put_contents($path, 'content');
                $seenPath = $path;
                $this->assertFileExists($path);

                return 'done';
            },
            extension: 'txt'
        );

        $this->assertSame('done', $result);
        $this->assertFileDoesNotExist($seenPath);
    }

    public function testUsingDeletesTheFileWhenTheCallbackThrows(): void
    {
        $seenPath = null;

        try {
            TempFile::using(function (string $path) use (&$seenPath): never {
                file_put_contents($path, 'content');
                $seenPath = $path;

                throw new RuntimeException('upload failed');
            });
            $this->fail('Exception should have propagated');
        } catch (RuntimeException $e) {
            $this->assertSame('upload failed', $e->getMessage());
        }

        $this->assertNotNull($seenPath);
        $this->assertFileDoesNotExist($seenPath);
    }

    public function testDeleteIgnoresNullEmptyAndMissingPaths(): void
    {
        $existing = TempFile::path('txt');
        file_put_contents($existing, 'content');

        TempFile::delete(null, '', TempFile::path('missing'), $existing);

        $this->assertFileDoesNotExist($existing);
    }
}
