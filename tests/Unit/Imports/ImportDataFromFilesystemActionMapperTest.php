<?php

declare(strict_types=1);

namespace Tests\Unit\Imports;

use Kanvas\Filesystem\Actions\ImportDataFromFilesystemAction;
use Kanvas\Filesystem\Models\FilesystemImports;
use Tests\TestCaseUnit;

class ImportDataFromFilesystemActionMapperTest extends TestCaseUnit
{
    public function testMapperResolvesConstantsColumnsNestingAndMissingColumns(): void
    {
        $result = $this->mapper(
            [
                'name' => 'Name',
                'productType' => ['name' => '_Default', 'weight' => 1],
                'missing' => 'No Such Column',
            ],
            ['Name' => 'Product A']
        );

        $this->assertSame('Product A', $result['name']);
        $this->assertSame(['name' => 'Default', 'weight' => 1], $result['productType']);
        $this->assertNull($result['missing']);
    }

    public function testMapperSplitsFilesAndTagsAndConvertsDates(): void
    {
        $result = $this->mapper(
            [
                'files' => 'Photos',
                'tags' => 'Tags',
                'created' => 'Created',
                'sold_at' => 'date_Sold',
            ],
            [
                'Photos' => 'https://cdn.test/a.jpg;https://cdn.test/b.jpg',
                'Tags' => 'sale, new',
                'Created' => '2026-01-15 10:30:00',
                'Sold' => '01/20/2026',
            ]
        );

        $this->assertSame(
            [
                ['url' => 'https://cdn.test/a.jpg', 'name' => 'a.jpg'],
                ['url' => 'https://cdn.test/b.jpg', 'name' => 'b.jpg'],
            ],
            $result['files']
        );
        $this->assertSame(['sale', 'new'], $result['tags']);
        $this->assertSame('2026-01-15 10:30:00', $result['created']);
        $this->assertStringStartsWith('2026-01-20', $result['sold_at']);
    }

    /**
     * @param array<string, mixed> $template
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function mapper(array $template, array $row): array
    {
        return new ImportDataFromFilesystemAction(new FilesystemImports())->mapper($template, $row);
    }
}
