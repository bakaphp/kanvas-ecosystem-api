<?php

declare(strict_types=1);

namespace Tests\Intelligence\Knowledge;

use DateTimeImmutable;
use Kanvas\Filesystem\Models\Filesystem;
use Kanvas\Filesystem\Services\FilesystemServices;
use Kanvas\Filesystem\Services\FileTextExtractor;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Writer\Ods;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
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
            'ods' => ['libreoffice.ods', true],
            'image not supported' => ['logo.png', false],
            'zip not supported' => ['bundle.zip', false],
            // Says nothing either way, so the bytes are read to find out — they, not the name, pick the parser.
            'no extension' => ['noext', true],
        ];
    }

    /** The tool's error copy names these, so a drift here silently misleads every agent. */
    public function testTheSupportedListIsTheOneTheToolAdvertises(): void
    {
        $this->assertSame(
            [
                'csv', 'docx', 'graphql', 'ini', 'js', 'json', 'jsx', 'log', 'markdown', 'md', 'ndjson',
                'ods', 'pdf', 'php', 'py', 'rb', 'sh', 'sql', 'toml', 'ts', 'tsv', 'tsx', 'txt', 'xls',
                'xlsx', 'xml', 'yaml', 'yml',
            ],
            collect(FileTextExtractor::supportedExtensions())->sort()->values()->all(),
        );
    }

    /**
     * A DOCX is a zip, and a zip member decompresses to an unbounded size — a small upload expands by
     * orders of magnitude and the text pipeline then copies whatever came out several times over.
     * The read itself is bounded, so what lands in memory is the cap, not the member.
     */
    public function testAnOversizeArchiveMemberIsBoundedAtTheRead(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'bomb') . '.docx';

        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::CREATE);
        $zip->addFromString('word/document.xml', '<w:p>' . str_repeat('A', 200_000) . '</w:p>');
        $zip->close();

        $text = new FileTextExtractor(1000)->extractFrom((string) file_get_contents($path), 'docx');
        @unlink($path);

        $this->assertStringContainsString('[truncated]', $text);
        $this->assertLessThan(5000, strlen($text), 'the cap must bound the read, not just the output');
    }

    /** A document under the cap keeps every character and gains no marker. */
    public function testASmallDocumentIsNotTruncated(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'doc') . '.docx';

        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::CREATE);
        $zip->addFromString('word/document.xml', '<w:p>hello world</w:p>');
        $zip->close();

        $text = new FileTextExtractor()->extractFrom((string) file_get_contents($path), 'docx');
        @unlink($path);

        $this->assertSame('hello world', $text);
    }

    /**
     * The cap has to bite during load(), not while rendering: by render time every row is already
     * built in memory, which is the cost the cap exists to avoid.
     */
    public function testASpreadsheetBeyondTheRowCapIsBoundedAtParse(): void
    {
        $book = new Spreadsheet();
        $sheet = $book->getActiveSheet();

        for ($row = 1; $row <= 2600; $row++) {
            $sheet->setCellValue('A' . $row, 'row' . $row);
        }

        $path = tempnam(sys_get_temp_dir(), 'sheet') . '.xlsx';
        new Xlsx($book)->save($path);

        $text = new FileTextExtractor()->extractFrom((string) file_get_contents($path), 'xlsx');
        @unlink($path);

        $this->assertStringContainsString('row1', $text);
        $this->assertStringContainsString('row2000', $text);
        $this->assertStringNotContainsString('row2600', $text);
        // The loader stops at the cap, so the rows past it never reach the render; without the note the model
        // reads a cut sheet as a complete one.
        $this->assertStringContainsString('range reaches row 2600; only the first 2000 rows were read', $text);
    }

    public function testASheetWiderThanTheColumnCapIsBoundedAndSaysSo(): void
    {
        $text = $this->extractWorkbook(['Wide' => $this->grid(rows: 3, columns: 150)]);

        $this->assertStringContainsString('r1c100', $text);
        $this->assertStringNotContainsString('r1c101', $text);
        $this->assertStringContainsString('range reaches column ET; only the first 100 columns were read', $text);
    }

    /** The note belongs to the sheet that was cut, not to the whole workbook. */
    public function testOnlyTheCutSheetCarriesTheNote(): void
    {
        $text = $this->extractWorkbook([
            'Narrow' => $this->grid(rows: 2, columns: 3),
            'Wide' => $this->grid(rows: 2, columns: 150),
        ]);

        [$narrow, $wide] = explode('# Sheet: Wide', $text);

        $this->assertStringNotContainsString('only the first', $narrow);
        $this->assertStringContainsString('only the first 100 columns were read', $wide);
    }

    public function testASheetInsideTheCapsCarriesNoNote(): void
    {
        $this->assertStringNotContainsString(
            'only the first',
            $this->extractWorkbook(['Small' => $this->grid(rows: 5, columns: 5)]),
        );
    }

    /**
     * The reader also asks the filter about column widths and row heights, never showing it a cell's
     * value — so a width set far past the data must not read as columns the model is missing.
     */
    public function testFormattingPastTheDataIsNotReportedAsMissingColumns(): void
    {
        $book = new Spreadsheet();
        $sheet = $book->getActiveSheet()->setTitle('Narrow');
        $sheet->fromArray([['a', 'b', 'c'], ['1', '2', '3']]);
        $sheet->getColumnDimension('EZ')->setWidth(20);

        $text = $this->extractBook($book);

        $this->assertStringContainsString("a\tb\tc", $text);
        $this->assertStringNotContainsString('columns were read', $text);
    }

    /**
     * The render shows the workbook's first 2000 rows across all sheets, so loading more than that is memory
     * spent on nothing; capping each sheet on its own let many large sheets multiply the cost.
     */
    public function testTheRowBudgetIsSharedAcrossSheetsInOrder(): void
    {
        $text = $this->extractWorkbook([
            'One' => $this->grid(rows: 1500, columns: 2),
            'Two' => $this->grid(rows: 1500, columns: 2),
            'Three' => $this->grid(rows: 1500, columns: 2),
        ]);

        [, $one, $two, $three] = preg_split('/# Sheet: \w+/', $text);

        $this->assertStringContainsString('r1500c1', $one);
        $this->assertStringNotContainsString('only the first', $one);
        $this->assertStringContainsString('r500c1', $two);
        $this->assertStringNotContainsString('r501c1', $two);
        $this->assertStringContainsString('only the first 500 rows were read', $two);
        $this->assertStringNotContainsString('r1c1', $three);
        $this->assertStringContainsString('not read: the 2000-row budget', $three);
    }

    /**
     * @param array<string, list<list<string>>> $sheets
     */
    private function extractWorkbook(array $sheets): string
    {
        $book = new Spreadsheet();
        $book->removeSheetByIndex(0);

        foreach ($sheets as $title => $rows) {
            $book->createSheet()->setTitle($title)->fromArray($rows);
        }

        return $this->extractBook($book);
    }

    private function extractBook(Spreadsheet $book): string
    {
        $path = tempnam(sys_get_temp_dir(), 'sheet') . '.xlsx';
        new Xlsx($book)->save($path);

        $text = new FileTextExtractor()->extractFrom((string) file_get_contents($path), 'xlsx');
        @unlink($path);

        return $text;
    }

    /**
     * @return list<list<string>>
     */
    private function grid(int $rows, int $columns): array
    {
        $grid = [];

        for ($row = 1; $row <= $rows; $row++) {
            $line = [];

            for ($column = 1; $column <= $columns; $column++) {
                $line[] = "r{$row}c{$column}";
            }

            $grid[] = $line;
        }

        return $grid;
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

    public function testAnOpenDocumentSpreadsheetIsRenderedAsTabSeparatedRows(): void
    {
        $book = new Spreadsheet();
        $sheet = $book->getActiveSheet();
        $sheet->setTitle('Inventory');
        $sheet->fromArray([['sku', 'price'], ['VIN123', 42000]], null, 'A1');

        $path = tempnam(sys_get_temp_dir(), 'odstest') . '.ods';
        new Ods($book)->save($path);
        $bytes = (string) file_get_contents($path);
        @unlink($path);

        $text = new FileTextExtractor()->extractFrom($bytes, 'ods');

        $this->assertStringContainsString('# Sheet: Inventory', $text);
        $this->assertStringContainsString("sku\tprice", $text);
        $this->assertStringContainsString("VIN123\t42000", $text);
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
    /**
     * The parser comes from the bytes, never a name: the uploader chooses the name, and letting it choose
     * the parser lets crafted bytes be routed into whichever decoder they target.
     */
    public function testEachFormatIsReadByItsOwnParserFromItsBytesAlone(): void
    {
        $extractor = new FileTextExtractor();

        $this->assertStringContainsString("sku\tqty", $extractor->extractFromBytes($this->spreadsheetBytes('Xlsx')));
        $this->assertStringContainsString("sku\tqty", $extractor->extractFromBytes($this->spreadsheetBytes('Xls')));
        $this->assertStringContainsString('"a": 1', $extractor->extractFromBytes('{"a":1}'));
        $this->assertSame("name,dept\nAda,Engineering", $extractor->extractFromBytes("name,dept\nAda,Engineering"));
    }

    /**
     * libmagic calls this file plain application/zip, so trusting it alone would refuse every such ODS; the
     * archive's contents are what identify it.
     */
    public function testAnOdsLibmagicCannotNameIsStillRead(): void
    {
        $bytes = $this->spreadsheetBytes('Ods');

        $this->assertSame('application/zip', FilesystemServices::detectMimeTypeFromBytes($bytes));
        $this->assertStringContainsString("sku\tqty", new FileTextExtractor()->extractFromBytes($bytes));
    }

    public function testAWordDocumentIsReadFromItsBytes(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'doc') . '.docx';

        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::CREATE);
        $zip->addFromString('word/document.xml', '<w:p>quarterly revenue</w:p>');
        $zip->addFromString('[Content_Types].xml', '<Types/>');
        $zip->close();

        $bytes = (string) file_get_contents($path);
        @unlink($path);

        $this->assertSame('quarterly revenue', new FileTextExtractor()->extractFromBytes($bytes));
    }

    /** Bytes no parser here recognises are refused, not dumped into a prompt as garbage text. */
    public function testUnrecognisedBinaryIsRefused(): void
    {
        $this->assertSame('', new FileTextExtractor()->extractFromBytes(random_bytes(512) . "\x00\x01\x02"));
    }

    private function spreadsheetBytes(string $writer): string
    {
        $book = new Spreadsheet();
        $book->getActiveSheet()->fromArray([['sku', 'qty'], ['PIM-001', '42']]);

        $path = tempnam(sys_get_temp_dir(), 'book');
        IOFactory::createWriter($book, $writer)->save($path);

        $bytes = (string) file_get_contents($path);
        @unlink($path);

        return $bytes;
    }

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
