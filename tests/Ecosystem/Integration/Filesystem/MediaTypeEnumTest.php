<?php

declare(strict_types=1);

namespace Tests\Ecosystem\Integration\Filesystem;

use Kanvas\Filesystem\Enums\MediaTypeEnum;
use Kanvas\Filesystem\Models\Filesystem;
use Tests\TestCase;

/**
 * `fromFilesystem()` is the multi-signal classifier — `file_type` may arrive as a bare
 * extension (`jpg`), a MIME type (`image/jpeg`), or the literal `unknown` written by
 * `HasFilesystemTrait::addFileFromUrl()` when the source URL has no extension. These tests
 * pin the precedence so the channel responder and the agent-chat upload partitioner
 * stay correct.
 */
class MediaTypeEnumTest extends TestCase
{
    public function testClassifiesByMimeTypePrefix(): void
    {
        $this->assertSame(
            MediaTypeEnum::IMAGE,
            MediaTypeEnum::fromFilesystem($this->fakeFile(
                fileType: 'image/jpeg',
                name: 'whatsapp-media',
                url: 'https://cdn/x/abc',
            )),
        );

        $this->assertSame(
            MediaTypeEnum::AUDIO,
            MediaTypeEnum::fromFilesystem($this->fakeFile(
                fileType: 'audio/mpeg',
                name: 'voice-note',
                url: 'https://cdn/x/abc',
            )),
        );

        $this->assertSame(
            MediaTypeEnum::VIDEO,
            MediaTypeEnum::fromFilesystem($this->fakeFile(
                fileType: 'video/mp4',
                name: 'clip',
                url: 'https://cdn/x/abc',
            )),
        );
    }

    public function testClassifiesByBareFileTypeExtension(): void
    {
        $this->assertSame(
            MediaTypeEnum::IMAGE,
            MediaTypeEnum::fromFilesystem($this->fakeFile(
                fileType: 'png',
                name: 'whatsapp-media',
                url: 'https://cdn/x/abc',
            )),
        );

        $this->assertSame(
            MediaTypeEnum::DOCUMENT,
            MediaTypeEnum::fromFilesystem($this->fakeFile(
                fileType: 'pdf',
                name: 'quote',
                url: 'https://cdn/x/abc',
            )),
        );
    }

    public function testFallsBackToExtensionInTheStoredName(): void
    {
        $this->assertSame(
            MediaTypeEnum::IMAGE,
            MediaTypeEnum::fromFilesystem($this->fakeFile(
                fileType: 'unknown',
                name: 'photo.jpg',
                url: 'https://cdn/x/abc',
            )),
        );
    }

    public function testFallsBackToExtensionInTheUrlPath(): void
    {
        $this->assertSame(
            MediaTypeEnum::IMAGE,
            MediaTypeEnum::fromFilesystem($this->fakeFile(
                fileType: 'unknown',
                name: '',
                url: 'https://cdn/x/abc.webp?token=xyz',
            )),
        );
    }

    public function testReturnsUnknownWhenNoSignalMatches(): void
    {
        $this->assertSame(
            MediaTypeEnum::UNKNOWN,
            MediaTypeEnum::fromFilesystem($this->fakeFile(
                fileType: 'unknown',
                name: 'audio',
                url: 'https://cdn/voice/xyz',
            )),
        );
    }

    public function testModelInstanceMethodDelegatesToTheEnumFactory(): void
    {
        $file = $this->fakeFile(fileType: 'image/jpeg', name: 'x', url: 'https://cdn/x');

        $this->assertTrue($file->mediaType()->isImage());
    }

    private function fakeFile(string $fileType, string $name, string $url): Filesystem
    {
        $file = new Filesystem();
        $file->file_type = $fileType;
        $file->name = $name;
        $file->url = $url;

        return $file;
    }
}
