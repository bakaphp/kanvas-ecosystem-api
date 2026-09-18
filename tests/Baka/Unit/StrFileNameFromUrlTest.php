<?php

declare(strict_types=1);

namespace Tests\Baka\Unit;

use Baka\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Naming a file in text a person or a model will read. Echoing the whole URL instead spills whatever
 * the query string carries — a storage signature, most often — into wherever that text is stored.
 */
class StrFileNameFromUrlTest extends TestCase
{
    public static function urlProvider(): array
    {
        return [
            'plain url' => ['https://example.com/uploads/report.pdf', 'report.pdf'],
            'signed url' => ['https://s3.example.com/a/b/q.xlsx?X-Amz-Signature=deadbeef', 'q.xlsx'],
            'fragment' => ['https://example.com/a/notes.md#section', 'notes.md'],
            'no extension' => ['https://example.com/files/invoice', 'invoice'],
            'encoded space' => ['https://example.com/my%20file.docx', 'my%20file.docx'],
            'bare path' => ['/var/tmp/local.txt', 'local.txt'],
        ];
    }

    #[DataProvider('urlProvider')]
    public function testItTakesTheFileNameOffTheUrl(string $url, string $expected): void
    {
        $this->assertSame($expected, Str::fileNameFromUrl($url));
    }

    public static function namelessProvider(): array
    {
        return [
            'trailing slash' => ['https://example.com/'],
            'host only' => ['https://example.com'],
            'empty' => [''],
            'null' => [null],
        ];
    }

    #[DataProvider('namelessProvider')]
    public function testItFallsBackWhenThereIsNoName(?string $url): void
    {
        $this->assertSame('', Str::fileNameFromUrl($url));
        $this->assertSame('attachment', Str::fileNameFromUrl($url, 'attachment'));
    }
}
