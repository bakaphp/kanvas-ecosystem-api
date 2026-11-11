<?php

declare(strict_types=1);

namespace Kanvas\Souk\Orders\Exports\FinancialReport\Concerns;

use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

trait StylesFinancialReportSheet
{
    private const string SECTION_FILL = 'E8F1FC';
    private const string HEADER_FILL = '0C355A';
    private const string PHYSICAL_HEADER_FILL = '175D57';
    private const string TOTAL_HEADER_FILL = '263B4D';
    private const string BORDER_COLOR = 'CCCCCC';
    private const string COUNT_FORMAT = '#,##0';
    private const string PERCENT_FORMAT = '0.0%';
    private const string DATE_FORMAT = 'dd/mm/yyyy';

    protected function moneyFormat(): string
    {
        $symbol = str_replace('"', '', (string) ($this->metadata['currency_symbol'] ?? '$'));

        return "\"{$symbol}\"#,##0.00;(\"{$symbol}\"#,##0.00);\"-\"";
    }

    protected function moneyLabel(string $label): string
    {
        $symbol = (string) ($this->metadata['currency_symbol'] ?? '$');

        return "{$label} ({$symbol})";
    }

    protected function netTotalLabel(): string
    {
        $beneficiary = trim((string) ($this->metadata['beneficiary'] ?? ''));

        return $this->moneyLabel($beneficiary === '' ? 'Recaudación neta total' : "Recaudación neta total de {$beneficiary}");
    }

    protected function styleSection(Worksheet $sheet, string $range): void
    {
        $sheet->getStyle($range)->applyFromArray([
            'font' => ['bold' => true],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => self::SECTION_FILL]],
            'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
        ]);
    }

    protected function styleHeader(Worksheet $sheet, string $range, string $fill = self::HEADER_FILL): void
    {
        $sheet->getStyle($range)->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $fill]],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical' => Alignment::VERTICAL_CENTER,
                'wrapText' => true,
            ],
        ]);
    }

    protected function styleBorders(Worksheet $sheet, string $range): void
    {
        $sheet->getStyle($range)->getBorders()->getAllBorders()->applyFromArray([
            'borderStyle' => Border::BORDER_THIN,
            'color' => ['rgb' => self::BORDER_COLOR],
        ]);
    }

    protected function setColumnWidths(Worksheet $sheet, array $widths): void
    {
        foreach ($widths as $column => $width) {
            $sheet->getColumnDimension($column)->setWidth($width);
        }
    }
}
