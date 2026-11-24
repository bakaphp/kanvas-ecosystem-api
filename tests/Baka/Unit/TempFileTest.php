<?php

declare(strict_types=1);

namespace Tests\Baka\Unit;

use Baka\Http\SafeUrlFetcher;
use Baka\Support\TempFile;
use Kanvas\Filesystem\Models\Filesystem;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\ResolvesFilesystemForTool;
use PHPUnit\Framework\Attributes\DataProvider;
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

    #[DataProvider('downloadOutcomes')]
    public function testDownloadedFileLeavesNoTemporaryFiles(string $outcome): void
    {
        $prefix = 'download-test-' . bin2hex(random_bytes(4)) . '-';
        $pattern = sys_get_temp_dir() . '/' . $prefix . '*';
        $reader = new class () {
            use ResolvesFilesystemForTool {
                withDownloadedFile as public readFile;
            }
        };
        $file = new Filesystem();
        $file->url = 'https://example.test/statement.csv';
        SafeUrlFetcher::fake(static fn (): string => $outcome === 'download'
            ? throw new RuntimeException('download failed')
            : 'date,amount');

        try {
            try {
                $result = $reader->readFile(
                    $file,
                    $prefix,
                    'csv',
                    function (string $path) use ($outcome): string {
                        $this->assertFileExists($path);
                        $this->assertStringEndsWith('.csv', $path);
                        $this->assertSame('date,amount', file_get_contents($path));

                        if ($outcome === 'parse') {
                            throw new RuntimeException('parse failed');
                        }

                        return 'parsed';
                    },
                );
                $this->assertSame('success', $outcome);
                $this->assertSame('parsed', $result);
            } catch (RuntimeException $exception) {
                $this->assertNotSame('success', $outcome);
                $this->assertSame($outcome . ' failed', $exception->getMessage());
            }

            $this->assertSame([], glob($pattern), 'Both the reservation and downloaded copy must be removed.');
        } finally {
            TempFile::delete(...(glob($pattern) ?: []));
        }
    }

    public static function downloadOutcomes(): array
    {
        return [['success'], ['download'], ['parse']];
    }
}
