<?php

declare(strict_types=1);

namespace Tests\Intelligence\Knowledge;

use Kanvas\Filesystem\Models\Filesystem;
use Kanvas\Intelligence\Knowledge\Services\KnowledgeTextExtractor;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class KnowledgeTextExtractorTest extends TestCase
{
    #[DataProvider('supportedExtensions')]
    public function testSupportsIndexableDocumentTypes(string $name, bool $expected): void
    {
        $file = new Filesystem(['name' => $name]);

        $this->assertSame($expected, new KnowledgeTextExtractor()->supports($file));
    }

    /** @return array<string, array{0: string, 1: bool}> */
    public static function supportedExtensions(): array
    {
        return [
            'txt' => ['policy.txt', true],
            'md' => ['README.md', true],
            'markdown' => ['guide.markdown', true],
            'pdf' => ['handbook.pdf', true],
            'uppercase pdf' => ['HANDBOOK.PDF', true],
            'docx not supported' => ['contract.docx', false],
            'xlsx not supported' => ['sheet.xlsx', false],
            'image not supported' => ['logo.png', false],
            'no extension' => ['noext', false],
        ];
    }
}
