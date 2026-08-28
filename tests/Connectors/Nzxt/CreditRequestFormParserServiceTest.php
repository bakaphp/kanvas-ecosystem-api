<?php

declare(strict_types=1);

namespace Tests\Connectors\Nzxt;

use Kanvas\Connectors\Nzxt\Services\CreditRequestFormParserService;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use RuntimeException;
use Tests\TestCase;

final class CreditRequestFormParserServiceTest extends TestCase
{
    private ?string $tempPath = null;

    protected function tearDown(): void
    {
        if ($this->tempPath !== null && file_exists($this->tempPath)) {
            unlink($this->tempPath);
        }

        parent::tearDown();
    }

    public function test_parses_a_real_shaped_credit_request_form(): void
    {
        $result = new CreditRequestFormParserService()->parse($this->writeSampleForm([
            ['Promotion Discount -99001', 'SKU-1000', 'Test Product A', 6, 30],
            ['Promotion Discount -99001', 'SKU-2000', 'Test Product B', 107, 4],
        ]));

        $this->assertSame('Test Customer Co', $result['customer_name']);
        $this->assertSame('EMEA', $result['region']);
        $this->assertSame('Test Region - USD', $result['tenant']);
        $this->assertSame('Test Campaign Q1 2026 (01/01-31/01)', $result['request_reference_no']);
        $this->assertCount(2, $result['lines']);
        $this->assertSame('99001', $result['lines'][0]['control_account_number']);
        $this->assertSame(180.0, $result['lines'][0]['amount']);
        $this->assertSame(428.0, $result['lines'][1]['amount']);
        $this->assertSame(608.0, $result['total']);
    }

    public function test_extracts_the_account_number_from_varied_label_formats(): void
    {
        $result = new CreditRequestFormParserService()->parse($this->writeSampleForm([
            ['ABC-88002', 'SKU-1', 'Widget', 2, 10],
            ['Test Discount- 77003', 'SKU-2', 'Gadget', 1, 5],
        ]));

        $this->assertSame('88002', $result['lines'][0]['control_account_number']);
        $this->assertSame('77003', $result['lines'][1]['control_account_number']);
    }

    public function test_throws_when_the_form_is_missing_required_labels(): void
    {
        $spreadsheet = new Spreadsheet();
        $spreadsheet->getActiveSheet()->setCellValue('A1', 'Not a credit request form');
        $this->tempPath = sys_get_temp_dir() . '/cnr_test_invalid_' . uniqid() . '.xlsx';
        new Xlsx($spreadsheet)->save($this->tempPath);

        $this->expectException(RuntimeException::class);

        new CreditRequestFormParserService()->parse($this->tempPath);
    }

    /**
     * @param list<array{0: string, 1: string, 2: string, 3: int, 4: float}> $lines
     */
    private function writeSampleForm(array $lines): string
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();

        $sheet->setCellValue('A1', 'Acme Co');
        $sheet->setCellValue('A4', 'Credit Request  Form');
        $sheet->setCellValue('A9', 'Customer Name:');
        $sheet->setCellValue('B9', 'Test Customer Co');
        $sheet->setCellValue('D9', 'Request  Date: ');
        $sheet->setCellValue('E9', '2026-06-01');
        $sheet->setCellValue('A11', 'Region:');
        $sheet->setCellValue('B11', 'EMEA');
        $sheet->setCellValue('A13', 'Tenant: ');
        $sheet->setCellValue('B13', 'Test Region - USD');
        $sheet->setCellValue('A15', 'Sales Name: ');
        $sheet->setCellValue('B15', 'Test Sales Rep');
        $sheet->setCellValue('D15', 'Request Reference No:');
        $sheet->setCellValue('E15', 'Test Campaign Q1 2026 (01/01-31/01)');
        $sheet->setCellValue('A18', 'Control Acct#');
        $sheet->setCellValue('F18', 'Amount');
        $sheet->setCellValue('B19', 'Product  number');
        $sheet->setCellValue('C19', 'Product name');
        $sheet->setCellValue('D19', 'Qty');
        $sheet->setCellValue('E19', 'Unit Price');

        $row = 20;
        foreach ($lines as [$controlAcct, $productNumber, $productName, $qty, $unitPrice]) {
            $sheet->setCellValue("A{$row}", $controlAcct);
            $sheet->setCellValue("B{$row}", $productNumber);
            $sheet->setCellValue("C{$row}", $productName);
            $sheet->setCellValue("D{$row}", $qty);
            $sheet->setCellValue("E{$row}", $unitPrice);
            $sheet->setCellValue("F{$row}", "=D{$row}*E{$row}");
            $row++;
        }

        $sheet->setCellValue("E{$row}", 'Total');
        $sheet->setCellValue("F{$row}", '=SUM(F20:F' . ($row - 1) . ')');

        $this->tempPath = sys_get_temp_dir() . '/cnr_test_' . uniqid() . '.xlsx';
        new Xlsx($spreadsheet)->save($this->tempPath);

        return $this->tempPath;
    }
}
