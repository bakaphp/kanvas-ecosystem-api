<?php

declare(strict_types=1);

namespace Tests\Unit\Imports;

use Kanvas\Filesystem\Services\CsvReaderService;
use Kanvas\Imports\Actions\MergeImportFilesAction;
use Kanvas\Imports\DataTransferObject\MergedImportFeed;
use Tests\TestCaseUnit;

class MergeImportFilesActionTest extends TestCaseUnit
{
    private const array MAPPING = ['handler' => 'VIN', 'sku' => 'VIN'];

    /** @var list<string> */
    private array $paths = [];

    protected function tearDown(): void
    {
        foreach ($this->paths as $path) {
            @unlink($path);
        }
        parent::tearDown();
    }

    public function testFirstFileWinsForAHandlerAndFiltersApplyPerFile(): void
    {
        $own = $this->file("\xEF\xBB\xBFVIN,New/Used,Price\nA,New,1\nB,Used,2\n");
        $shared = $this->file("VIN,New/Used,Price,Extra\nB,Used,99,x\nC,used,3,y\nD,New,4,z\n");

        $feed = $this->merge([
            ['path' => $own, 'filter' => null],
            ['path' => $shared, 'filter' => ['column' => 'New/Used', 'in' => ['Used']]],
        ]);

        $this->assertSame(3, $feed->rows);
        $this->assertSame(1, $feed->skippedRows, 'B from the shared file');
        $this->assertSame(['A', 'B', 'C'], $feed->skus);
        $this->assertSame(
            [
                ['VIN', 'New/Used', 'Price', 'Extra'],
                ['A', 'New', '1', ''],
                ['B', 'Used', '2', ''],
                ['C', 'used', '3', 'y'],
            ],
            $this->rows($feed->path)
        );
    }

    public function testRowsWithoutAHandlerAreSkippedNotMergedIntoOneProduct(): void
    {
        $feed = $this->merge([['path' => $this->file("VIN,Price\n,1\n  ,2\nA,3\n"), 'filter' => null]]);

        $this->assertSame(1, $feed->rows);
        $this->assertSame(2, $feed->skippedRows);
    }

    public function testVariantRowsOfTheSameHandlerStayTogether(): void
    {
        $feed = new MergeImportFilesAction(
            [['path' => $this->file("Handle,SKU\nshirt,S-1\nmug,M-1\nshirt,S-2\n"), 'filter' => null]],
            ['handler' => 'Handle', 'sku' => 'SKU'],
            $this->outputPath()
        )->execute();

        $this->assertSame([['Handle', 'SKU'], ['shirt', 'S-1'], ['shirt', 'S-2'], ['mug', 'M-1']], $this->rows($feed->path));
        $this->assertEqualsCanonicalizing(['S-1', 'S-2', 'M-1'], $feed->skus);
    }

    public function testBackslashEscapedQuotesAreRepairedBeforeParsing(): void
    {
        $path = $this->file("VIN,Description\nA,\"12\\\" rims\\\",\nB,\"tow pkg\\\"\n");

        CsvReaderService::repairBackslashEscapedQuotes($path);

        $this->assertSame("VIN,Description\nA,\"12\\\" rims\",\nB,\"tow pkg\"\n", file_get_contents($path));
    }

    private function merge(array $files): MergedImportFeed
    {
        return new MergeImportFilesAction($files, self::MAPPING, $this->outputPath())->execute();
    }

    private function file(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'merge') . '.csv';
        file_put_contents($path, $contents);
        $this->paths[] = $path;

        return $path;
    }

    private function outputPath(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'merged') . '.csv';
        $this->paths[] = $path;

        return $path;
    }

    /**
     * @return list<list<string>>
     */
    private function rows(string $path): array
    {
        $rows = [];
        $handle = fopen($path, 'r');
        while (($row = fgetcsv($handle, escape: '')) !== false) {
            $rows[] = $row;
        }
        fclose($handle);

        return $rows;
    }
}
