<?php

declare(strict_types=1);

namespace Tests\Intelligence\Knowledge;

use DateTimeImmutable;
use Kanvas\Filesystem\Models\Filesystem;
use Kanvas\Filesystem\Services\FileTextExtractor;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use ZipArchive;

class FileTextExtractorTest extends TestCase
{
    #[DataProvider('supportedExtensions')]
    public function testSupportsIndexableDocumentTypes(string $name, bool $expected): void
    {
        $file = new Filesystem(['name' => $name]);

        $this->assertSame($expected, new FileTextExtractor()->supports($file));
    }

    /** @return array<string, array{0: string, 1: bool}> */
    public static function supportedExtensions(): array
    {
        return [
            'txt' => ['policy.txt', true],
            'md' => ['README.md', true],
            'markdown' => ['guide.markdown', true],
            'log' => ['worker.log', true],
            'pdf' => ['handbook.pdf', true],
            'uppercase pdf' => ['HANDBOOK.PDF', true],
            'csv' => ['employees.csv', true],
            'tsv' => ['export.tsv', true],
            'json' => ['payload.json', true],
            'docx' => ['contract.docx', true],
            'xlsx' => ['sheet.xlsx', true],
            'xls' => ['legacy.xls', true],
            'image not supported' => ['logo.png', false],
            'zip not supported' => ['bundle.zip', false],
            'no extension' => ['noext', false],
        ];
    }

    /** The tool's error copy names these, so a drift here silently misleads every agent. */
    public function testTheSupportedListIsTheOneTheToolAdvertises(): void
    {
        $this->assertSame(
            ['csv', 'docx', 'json', 'log', 'markdown', 'md', 'pdf', 'tsv', 'txt', 'xls', 'xlsx'],
            collect(FileTextExtractor::supportedExtensions())->sort()->values()->all(),
        );
    }

    public function testAnUnsupportedFileIsASkipNotAFailure(): void
    {
        $file = new Filesystem(['name' => 'logo.png', 'url' => 'https://example.invalid/logo.png']);

        // No fetch is attempted — the extension is rejected before the URL is touched, which is why
        // an unreachable host here does not throw.
        $this->assertSame('', new FileTextExtractor()->extract($file));
    }

    public function testCsvIsReturnedAsIsBecauseThatIsAlreadyItsReadableForm(): void
    {
        $csv = "name,dept\nAda,Engineering\nGrace,Engineering";

        $this->assertSame($csv, new FileTextExtractor()->extractFrom($csv, 'csv'));
    }

    public function testAByteOrderMarkIsStrippedSoTheFirstHeaderIsNotCorrupted(): void
    {
        $text = new FileTextExtractor()->extractFrom("\xEF\xBB\xBFname,dept\nAda,Engineering", 'csv');

        $this->assertStringStartsWith('name,dept', $text);
    }

    public function testMinifiedJsonComesBackReadable(): void
    {
        $text = new FileTextExtractor()->extractFrom('{"a":1,"b":[2,3]}', 'json');

        $this->assertStringContainsString("\n", $text);
        $this->assertSame(['a' => 1, 'b' => [2, 3]], json_decode($text, true));
    }

    /** Invalid JSON is still content worth handing over — refusing it would lose the whole file. */
    public function testInvalidJsonIsReturnedRaw(): void
    {
        $this->assertSame('{not json', new FileTextExtractor()->extractFrom('{not json', 'json'));
    }

    public function testAnExcelSheetIsRenderedAsTabSeparatedRowsUnderItsSheetName(): void
    {
        $book = new Spreadsheet();
        $sheet = $book->getActiveSheet();
        $sheet->setTitle('Employees');
        $sheet->fromArray([['name', 'dept'], ['Ada', 'Engineering']], null, 'A1');

        $path = tempnam(sys_get_temp_dir(), 'xlsxtest') . '.xlsx';
        new Xlsx($book)->save($path);
        $bytes = (string) file_get_contents($path);
        @unlink($path);

        $text = new FileTextExtractor()->extractFrom($bytes, 'xlsx');

        $this->assertStringContainsString('# Sheet: Employees', $text);
        $this->assertStringContainsString("name\tdept", $text);
        $this->assertStringContainsString("Ada\tEngineering", $text);
    }

    /**
     * Paragraph and tab breaks must survive tag-stripping, or a table collapses into one unbroken
     * string that no model can read as rows.
     */
    public function testDocxParagraphsAndTabsBecomeWhitespace(): void
    {
        $xml = '<w:document><w:body>'
            . '<w:p><w:r><w:t>First line</w:t></w:r></w:p>'
            . '<w:p><w:r><w:t>Ada</w:t></w:r><w:tab/><w:r><w:t>Engineering</w:t></w:r></w:p>'
            . '</w:body></w:document>';

        $path = tempnam(sys_get_temp_dir(), 'docxtest') . '.docx';
        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::CREATE);
        $zip->addFromString('word/document.xml', $xml);
        $zip->close();
        $bytes = (string) file_get_contents($path);
        @unlink($path);

        $text = new FileTextExtractor()->extractFrom($bytes, 'docx');

        $this->assertStringContainsString("First line\n", $text);
        $this->assertStringContainsString("Ada\tEngineering", $text);
    }

    /** A corrupt upload is a skip, never an exception into the agent turn. */
    public function testCorruptBinaryIsASkipNotAThrow(): void
    {
        $extractor = new FileTextExtractor();

        $this->assertSame('', $extractor->extractFrom('not a pdf at all', 'pdf'));
        $this->assertSame('', $extractor->extractFrom('not a zip at all', 'docx'));
        $this->assertSame('', $extractor->extractFrom('not a workbook', 'xlsx'));
    }

    /**
     * A date cell holds a serial number; only the display format makes it a date. Reading raw handed
     * the model 46265 instead of 2026-08-31 — plausible-looking noise it would then reason from.
     */
    public function testDatesAndCurrencyComeBackAsTheSheetDisplaysThem(): void
    {
        $book = new Spreadsheet();
        $sheet = $book->getActiveSheet();
        $sheet->setCellValue('A1', 'hired');
        $sheet->setCellValue('B1', 'salary');
        $sheet->setCellValue('A2', Date::PHPToExcel(new DateTimeImmutable('2026-08-31')));
        $sheet->getStyle('A2')->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_DATE_YYYYMMDD);
        $sheet->setCellValue('B2', 45000);
        $sheet->getStyle('B2')->getNumberFormat()->setFormatCode('$#,##0.00');

        $text = new FileTextExtractor()->extractFrom($this->xlsxBytes($book), 'xlsx');

        $this->assertStringContainsString('2026-08-31', $text);
        $this->assertStringNotContainsString('46265', $text);
        $this->assertStringContainsString('$45,000.00', $text);
    }

    private function xlsxBytes(Spreadsheet $book): string
    {
        $path = tempnam(sys_get_temp_dir(), 'xlsxtest') . '.xlsx';
        new Xlsx($book)->save($path);
        $bytes = (string) file_get_contents($path);
        @unlink($path);

        return $bytes;
    }
}
