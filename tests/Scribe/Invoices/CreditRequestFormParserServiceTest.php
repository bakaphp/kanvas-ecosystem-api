<?php

declare(strict_types=1);

namespace Tests\Scribe\Invoices;

use Kanvas\Scribe\Invoices\Services\CreditRequestFormParserService;
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
        $result = CreditRequestFormParserService::parse($this->writeSampleForm([
            ['Promotion Discount -41045', 'CM-H92FW-R1', 'NZXT H9 Flow RGB (2025) - All White', 6, 30],
            ['Promotion Discount -41045', 'RL-KR280-B1', 'NZXT Kraken 280 Black RGB', 107, 4],
        ]));

        $this->assertSame('Proshop', $result['customer_name']);
        $this->assertSame('EMEA', $result['region']);
        $this->assertSame('Germany- USD', $result['tenant']);
        $this->assertSame('Proshop Overstock May 2026 Sell-Out (20/04-17/05)', $result['request_reference_no']);
        $this->assertCount(2, $result['lines']);
        $this->assertSame('41045', $result['lines'][0]['control_account_number']);
        $this->assertSame(180.0, $result['lines'][0]['amount']);
        $this->assertSame(428.0, $result['lines'][1]['amount']);
        $this->assertSame(608.0, $result['total']);
    }

    public function test_extracts_the_account_number_from_varied_label_formats(): void
    {
        $result = CreditRequestFormParserService::parse($this->writeSampleForm([
            ['MDF-72300', 'SKU-1', 'Widget', 2, 10],
            ['Price Protection- 41052', 'SKU-2', 'Gadget', 1, 5],
        ]));

        $this->assertSame('72300', $result['lines'][0]['control_account_number']);
        $this->assertSame('41052', $result['lines'][1]['control_account_number']);
    }

    public function test_throws_when_the_form_is_missing_required_labels(): void
    {
        $spreadsheet = new Spreadsheet();
        $spreadsheet->getActiveSheet()->setCellValue('A1', 'Not a credit request form');
        $this->tempPath = sys_get_temp_dir() . '/cnr_test_invalid_' . uniqid() . '.xlsx';
        new Xlsx($spreadsheet)->save($this->tempPath);

        $this->expectException(RuntimeException::class);

        CreditRequestFormParserService::parse($this->tempPath);
    }

    /**
     * @param list<array{0: string, 1: string, 2: string, 3: int, 4: float}> $lines
     */
    private function writeSampleForm(array $lines): string
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();

        $sheet->setCellValue('A1', 'NZXT');
        $sheet->setCellValue('A4', 'Credit Request  Form');
        $sheet->setCellValue('A9', 'Customer Name:');
        $sheet->setCellValue('B9', 'Proshop');
        $sheet->setCellValue('D9', 'Request  Date: ');
        $sheet->setCellValue('E9', '2026-06-01');
        $sheet->setCellValue('A11', 'Region:');
        $sheet->setCellValue('B11', 'EMEA');
        $sheet->setCellValue('A13', 'Tenant: ');
        $sheet->setCellValue('B13', 'Germany- USD');
        $sheet->setCellValue('A15', 'Sales Name: ');
        $sheet->setCellValue('B15', 'Philip Bakhramov');
        $sheet->setCellValue('D15', 'Request Reference No:');
        $sheet->setCellValue('E15', 'Proshop Overstock May 2026 Sell-Out (20/04-17/05)');
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
