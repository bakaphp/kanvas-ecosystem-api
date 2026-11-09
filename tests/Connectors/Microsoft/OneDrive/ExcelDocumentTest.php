<?php

declare(strict_types=1);

namespace Tests\Connectors\Microsoft\OneDrive;

use Kanvas\Connectors\Microsoft\OneDrive\Documents\ExcelDocument;
use Kanvas\Connectors\Microsoft\OneDrive\Enums\OneDriveErrorReasonEnum;
use Kanvas\Connectors\Microsoft\OneDrive\Exceptions\OneDriveException;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ZipArchive;

class ExcelDocumentTest extends TestCase
{
    private function fixture(): string
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Ventas');
        $sheet->fromArray([
            ['Mes', 'Total'],
            ['Enero', 100],
            ['Febrero', 200],
        ]);

        $other = $spreadsheet->createSheet();
        $other->setTitle('Notas');
        $other->setCellValue('A1', 'nota importante');

        return $this->save($spreadsheet);
    }

    private function save(Spreadsheet $spreadsheet): string
    {
        $path = tempnam(sys_get_temp_dir(), 'kanvas-xlsx-test');

        try {
            IOFactory::createWriter($spreadsheet, 'Xlsx')->save($path);

            return (string) file_get_contents($path);
        } finally {
            @unlink($path);
        }
    }

    private function load(string $bytes): Spreadsheet
    {
        $path = tempnam(sys_get_temp_dir(), 'kanvas-xlsx-test');

        try {
            file_put_contents($path, $bytes);

            return IOFactory::createReader('Xlsx')->load($path);
        } finally {
            @unlink($path);
        }
    }

    private function withPart(string $bytes, string $part): string
    {
        $path = tempnam(sys_get_temp_dir(), 'kanvas-xlsx-test');

        try {
            file_put_contents($path, $bytes);
            $zip = new ZipArchive();
            $zip->open($path);
            $zip->addFromString($part, '<x/>');
            $zip->close();

            return (string) file_get_contents($path);
        } finally {
            @unlink($path);
        }
    }

    public function test_read_returns_rows_of_the_first_sheet(): void
    {
        $result = ExcelDocument::read($this->fixture());

        $this->assertSame('Ventas', $result['sheet']);
        $this->assertSame(['Ventas', 'Notas'], $result['sheets']);
        $this->assertSame('A1:B3', $result['range']);
        $this->assertSame([['Mes', 'Total'], ['Enero', 100], ['Febrero', 200]], $result['rows']);
        $this->assertFalse($result['truncated']);
    }

    public function test_read_respects_sheet_and_range(): void
    {
        $result = ExcelDocument::read($this->fixture(), 'Ventas', 'B2:B3');

        $this->assertSame('B2:B3', $result['range']);
        $this->assertSame([[100], [200]], $result['rows']);

        $notes = ExcelDocument::read($this->fixture(), 'Notas');
        $this->assertSame('Notas', $notes['sheet']);
        $this->assertSame([['nota importante']], $notes['rows']);
    }

    public function test_read_truncates_at_500_rows(): void
    {
        $rows = [];
        for ($i = 1; $i <= 501; $i++) {
            $rows[] = [$i];
        }

        $result = ExcelDocument::read(ExcelDocument::create($rows));

        $this->assertCount(500, $result['rows']);
        $this->assertTrue($result['truncated']);
    }

    public function test_write_sets_cells_and_formulas(): void
    {
        $bytes = ExcelDocument::write(
            $this->fixture(),
            'Ventas',
            ['B2' => 500, 'C2' => '=B2*2'],
            []
        );

        $result = ExcelDocument::read($bytes, 'Ventas', 'B2:C2');

        $this->assertSame([[500, 1000]], $result['rows']);
    }

    public function test_append_rows_go_after_the_last_row(): void
    {
        $bytes = ExcelDocument::write(
            $this->fixture(),
            'Ventas',
            [],
            [['Marzo', 300], ['Abril', 400]]
        );

        $result = ExcelDocument::read($bytes, 'Ventas');

        $this->assertSame(['Marzo', 300], $result['rows'][3]);
        $this->assertSame(['Abril', 400], $result['rows'][4]);
        $this->assertCount(5, $result['rows']);
    }

    public function test_write_keeps_other_sheets(): void
    {
        $bytes = ExcelDocument::write(
            $this->fixture(),
            'Ventas',
            ['B2' => 1],
            []
        );

        $result = ExcelDocument::read($bytes, 'Notas');

        $this->assertSame(['Ventas', 'Notas'], $result['sheets']);
        $this->assertSame([['nota importante']], $result['rows']);
        $this->assertSame(200, ExcelDocument::read($bytes, 'Ventas', 'B3:B3')['rows'][0][0]);
    }

    public function test_unknown_sheet_is_not_found(): void
    {
        try {
            ExcelDocument::write(
                $this->fixture(),
                'Nope',
                ['A1' => 1],
                []
            );
            $this->fail('Expected OneDriveException');
        } catch (OneDriveException $e) {
            $this->assertSame(OneDriveErrorReasonEnum::NOT_FOUND, $e->reason);
            $this->assertSame('Sheet not found: Nope', $e->getMessage());
        }
    }

    public function test_non_xlsx_bytes_are_unsupported(): void
    {
        try {
            ExcelDocument::read('this is not a spreadsheet');
            $this->fail('Expected OneDriveException');
        } catch (OneDriveException $e) {
            $this->assertSame(OneDriveErrorReasonEnum::UNSUPPORTED_TYPE, $e->reason);
        }
    }

    public function test_invalid_cell_reference_is_rejected(): void
    {
        try {
            ExcelDocument::write(
                $this->fixture(),
                'Ventas',
                ['not a cell' => 1],
                []
            );
            $this->fail('Expected OneDriveException');
        } catch (OneDriveException $e) {
            $this->assertSame(OneDriveErrorReasonEnum::REQUEST_FAILED, $e->reason);
            $this->assertSame('Invalid cell reference: not a cell', $e->getMessage());
        }
    }

    public function test_create_builds_a_workbook_and_strips_invalid_xml_chars(): void
    {
        $bytes = ExcelDocument::create([['a', "b\x01c"], [1, '=A2']]);

        $spreadsheet = $this->load($bytes);
        $this->assertSame('bc', $spreadsheet->getActiveSheet()->getCell('B1')->getValue());
        $this->assertSame('=A2', $spreadsheet->getActiveSheet()->getCell('B2')->getValue());
    }

    public function test_create_accepts_empty_rows(): void
    {
        $this->assertSame([[null]], ExcelDocument::read(ExcelDocument::create([]))['rows']);
    }

    public function test_unparseable_range_is_reported_as_invalid_range(): void
    {
        try {
            ExcelDocument::read($this->fixture(), 'Ventas', 'not a range');
            $this->fail('Expected OneDriveException');
        } catch (OneDriveException $e) {
            $this->assertSame(OneDriveErrorReasonEnum::REQUEST_FAILED, $e->reason);
            $this->assertSame('Invalid range: not a range', $e->getMessage());
        }
    }

    public function test_formula_calculation_failure_is_not_reported_as_invalid_range(): void
    {
        $spreadsheet = new Spreadsheet();
        $spreadsheet->getActiveSheet()->setCellValue('A1', '=SUM(');
        $path = tempnam(sys_get_temp_dir(), 'kanvas-xlsx-test');

        try {
            $writer = IOFactory::createWriter($spreadsheet, 'Xlsx');
            $writer->setPreCalculateFormulas(false);
            $writer->save($path);
            $bytes = (string) file_get_contents($path);
        } finally {
            @unlink($path);
        }

        try {
            ExcelDocument::read($bytes);
            $this->fail('Expected OneDriveException');
        } catch (OneDriveException $e) {
            $this->assertSame(OneDriveErrorReasonEnum::REQUEST_FAILED, $e->reason);
            $this->assertStringStartsWith('Could not calculate the sheet: ', $e->getMessage());
            $this->assertNotNull($e->getPrevious());
        }
    }

    public function test_save_failure_is_wrapped(): void
    {
        try {
            ExcelDocument::write(
                $this->fixture(),
                'Ventas',
                ['A1' => '=SUM('],
                []
            );
            $this->fail('Expected OneDriveException');
        } catch (OneDriveException $e) {
            $this->assertSame(OneDriveErrorReasonEnum::REQUEST_FAILED, $e->reason);
            $this->assertStringStartsWith('Could not save the workbook: ', $e->getMessage());
            $this->assertNotNull($e->getPrevious());
        }
    }

    public function test_write_accepts_a_workbook_made_by_create(): void
    {
        $bytes = ExcelDocument::write(
            ExcelDocument::create([['a']]),
            null,
            ['A1' => 'b'],
            []
        );

        $this->assertSame([['b']], ExcelDocument::read($bytes)['rows']);
    }

    public function test_write_accepts_a_workbook_with_legacy_notes(): void
    {
        $spreadsheet = $this->load($this->fixture());
        $spreadsheet->getSheet(0)->getComment('A1')->getText()->createText('revisar');
        $bytes = $this->save($spreadsheet);

        $written = ExcelDocument::write(
            $bytes,
            null,
            ['B2' => 1],
            []
        );

        $this->assertSame('revisar', $this->load($written)->getSheet(0)->getComment('A1')->getText()->getPlainText());
    }

    public static function partsPhpSpreadsheetWouldDrop(): array
    {
        return [
            'threaded comments' => ['xl/threadedComments/threadedComment1.xml'],
            'persons' => ['xl/persons/person.xml'],
            'connections' => ['xl/connections.xml'],
            'pivot table' => ['xl/pivotTables/pivotTable1.xml'],
        ];
    }

    #[DataProvider('partsPhpSpreadsheetWouldDrop')]
    public function test_write_refuses_workbooks_with_parts_phpspreadsheet_would_drop(string $part): void
    {
        $bytes = $this->withPart(ExcelDocument::create([['a']]), $part);

        try {
            ExcelDocument::write(
                $bytes,
                null,
                ['A1' => 1],
                []
            );
            $this->fail('Expected OneDriveException');
        } catch (OneDriveException $e) {
            $this->assertSame(OneDriveErrorReasonEnum::UNSUPPORTED_TYPE, $e->reason);
            $this->assertSame('This workbook contains features that would be lost if edited (' . $part . ')', $e->getMessage());
        }

        $this->assertSame([['a']], ExcelDocument::read($bytes)['rows']);
    }

    public function test_read_caps_columns_at_50(): void
    {
        $result = ExcelDocument::read(ExcelDocument::create([range(1, 60), range(61, 120)]));

        $this->assertCount(2, $result['rows']);
        $this->assertSame(range(1, 50), $result['rows'][0]);
        $this->assertSame(range(61, 110), $result['rows'][1]);
        $this->assertTrue($result['truncated']);

        $this->assertFalse(ExcelDocument::read(ExcelDocument::create([range(1, 50)]))['truncated']);
    }

    public function test_default_sheet_is_the_first_sheet_not_the_active_one(): void
    {
        $spreadsheet = $this->load($this->fixture());
        $spreadsheet->setActiveSheetIndex(1);
        $bytes = $this->save($spreadsheet);
        $this->assertSame('Notas', $this->load($bytes)->getActiveSheet()->getTitle());

        $this->assertSame('Ventas', ExcelDocument::read($bytes)['sheet']);

        $written = ExcelDocument::write(
            $bytes,
            null,
            ['B2' => 999],
            []
        );

        $this->assertSame([[999]], ExcelDocument::read($written, 'Ventas', 'B2:B2')['rows']);
        $this->assertSame([['nota importante']], ExcelDocument::read($written, 'Notas')['rows']);
    }

    public function test_non_scalar_cell_value_is_rejected(): void
    {
        try {
            ExcelDocument::write(
                $this->fixture(),
                'Ventas',
                ['A1' => ['x']],
                []
            );
            $this->fail('Expected OneDriveException');
        } catch (OneDriveException $e) {
            $this->assertSame(OneDriveErrorReasonEnum::REQUEST_FAILED, $e->reason);
            $this->assertSame('Invalid cell value for A1', $e->getMessage());
        }
    }
}
