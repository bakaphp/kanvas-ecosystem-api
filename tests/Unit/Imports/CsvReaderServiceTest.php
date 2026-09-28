<?php

declare(strict_types=1);

namespace Tests\Unit\Imports;

use Kanvas\Filesystem\Services\CsvReaderService;
use Tests\TestCaseUnit;

class CsvReaderServiceTest extends TestCaseUnit
{
    /** @var array<int, string> */
    private array $tempPaths = [];

    protected function tearDown(): void
    {
        foreach ($this->tempPaths as $path) {
            if (file_exists($path)) {
                @unlink($path);
            }
        }
        parent::tearDown();
    }

    public function testReadsACommaDelimitedFile(): void
    {
        $path = $this->writeRaw("Slug,Name,Tags\nprod-a,Alpha,\"summer, sale\"\n");

        $reader = CsvReaderService::fromPath($path);
        $reader->setHeaderOffset(0);

        $this->assertSame(['Slug', 'Name', 'Tags'], $reader->getHeader());
        $this->assertSame('summer, sale', $reader->nth(0)['Tags']);
    }

    public function testReadsASemicolonDelimitedFile(): void
    {
        // Excel's default export in most of Europe and Latin America. Before
        // detection this parsed as one column and the import silently no-oped.
        $path = $this->writeRaw("Slug;Name;Tags\nprod-a;Alpha;summer, sale\n");

        $reader = CsvReaderService::fromPath($path);
        $reader->setHeaderOffset(0);

        $this->assertSame(['Slug', 'Name', 'Tags'], $reader->getHeader());
        $this->assertSame(
            'summer, sale',
            $reader->nth(0)['Tags'],
            'The field delimiter and the in-cell list delimiter resolve independently',
        );
    }

    public function testReadsATabDelimitedFile(): void
    {
        $path = $this->writeRaw("Slug\tName\tTags\nprod-a\tAlpha\tsummer\n");

        $reader = CsvReaderService::fromPath($path);
        $reader->setHeaderOffset(0);

        $this->assertSame(['Slug', 'Name', 'Tags'], $reader->getHeader());
    }

    public function testCommaWinsWhenCellsContainSemicolons(): void
    {
        // The file is comma-delimited; semicolons appear only inside a cell.
        // Detection scores column consistency, so it must not be misled.
        $path = $this->writeRaw("Slug,Name,Tags\nprod-a,Alpha,a;b;c\nprod-b,Beta,d;e\n");

        $reader = CsvReaderService::fromPath($path);
        $reader->setHeaderOffset(0);

        $this->assertSame(['Slug', 'Name', 'Tags'], $reader->getHeader());
        $this->assertSame('a;b;c', $reader->nth(0)['Tags']);
    }

    public function testCommaWinsWhenACellHoldsMorePipesThanTheRowHasCommas(): void
    {
        // `Photo Url List` is pipe-separated in every dealer feed, so scoring data rows picks `|`,
        // every column parses into one and the whole import maps to null. The header row is the
        // only line that is all column names.
        $path = $this->writeRaw(
            "VIN,Make,Photo Url List\n"
            . "1GYK,Cadillac,\"https://a/1.jpg|https://a/2.jpg|https://a/3.jpg|https://a/4.jpg|https://a/5.jpg\"\n"
            . "2HGF,Honda,\"https://b/1.jpg|https://b/2.jpg|https://b/3.jpg|https://b/4.jpg|https://b/5.jpg\"\n"
        );

        $reader = CsvReaderService::fromPath($path);
        $reader->setHeaderOffset(0);

        $this->assertSame(['VIN', 'Make', 'Photo Url List'], $reader->getHeader());
        $this->assertSame('1GYK', $reader->nth(0)['VIN']);
        $this->assertStringContainsString('|', $reader->nth(0)['Photo Url List']);
    }

    public function testAQuotedHeaderNameMayContainTheDelimiter(): void
    {
        $path = $this->writeRaw("VIN,\"Dealer, Inc\",Make\n1GYK,Acme,Cadillac\n");

        $reader = CsvReaderService::fromPath($path);
        $reader->setHeaderOffset(0);

        $this->assertSame(['VIN', 'Dealer, Inc', 'Make'], $reader->getHeader());
    }

    public function testSingleColumnFileFallsBackToTheDefaultDelimiter(): void
    {
        $path = $this->writeRaw("Slug\nprod-a\nprod-b\n");

        $reader = CsvReaderService::fromPath($path);
        $reader->setHeaderOffset(0);

        $this->assertSame(['Slug'], $reader->getHeader());
        $this->assertSame(CsvReaderService::DEFAULT_DELIMITER, $reader->getDelimiter());
    }

    public function testEmptyFileFallsBackToTheDefaultDelimiter(): void
    {
        $path = $this->writeRaw('');

        $reader = CsvReaderService::fromPath($path);

        $this->assertSame(CsvReaderService::DEFAULT_DELIMITER, $reader->getDelimiter());
    }

    private function writeRaw(string $content): string
    {
        $path = tempnam(sys_get_temp_dir(), 'csvreader') . '.csv';
        file_put_contents($path, $content);
        $this->tempPaths[] = $path;

        return $path;
    }
}
