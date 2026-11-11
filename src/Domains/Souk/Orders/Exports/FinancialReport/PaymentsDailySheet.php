<?php

declare(strict_types=1);

namespace Kanvas\Souk\Orders\Exports\FinancialReport;

use Illuminate\Support\Carbon;
use Kanvas\Souk\Orders\Exports\FinancialReport\Concerns\StylesFinancialReportSheet;
use Kanvas\Souk\Payments\Enums\PaymentChannelEnum;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;
use Override;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class PaymentsDailySheet implements FromArray, WithTitle, WithEvents
{
    use StylesFinancialReportSheet;

    public function __construct(
        private readonly array $report,
        private readonly array $metadata = [],
    ) {
    }

    #[Override]
    public function array(): array
    {
        return [];
    }

    public function title(): string
    {
        return 'Diario de pago';
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => fn (AfterSheet $event) => $this->build($event->sheet->getDelegate()),
        ];
    }

    private function build(Worksheet $sheet): void
    {
        $digital = PaymentChannelEnum::DIGITAL->value;
        $physical = PaymentChannelEnum::PHYSICAL->value;

        $sheet->setCellValue('A1', 'Pagos por día según fecha de pago');
        $sheet->mergeCells('A1:J1');
        $this->styleSection($sheet, 'A1:J1');

        $sheet->fromArray([[
            'Fecha de pago',
            'Total de transacciones',
            'Digital: cantidad',
            $this->moneyLabel('Digital: monto bruto'),
            $this->moneyLabel('Digital: recaudación neta'),
            'Físico: cantidad',
            $this->moneyLabel('Físico: monto bruto'),
            $this->moneyLabel('Físico: recaudación neta'),
            $this->moneyLabel('Recaudación bruta total'),
            $this->netTotalLabel(),
        ]], null, 'A2');
        $this->styleHeader($sheet, 'A2:E2');
        $this->styleHeader($sheet, 'F2:H2', self::PHYSICAL_HEADER_FILL);
        $this->styleHeader($sheet, 'I2:J2', self::TOTAL_HEADER_FILL);
        $sheet->getRowDimension(2)->setRowHeight(32);

        $row = 3;
        foreach ($this->report['daily'] as $day) {
            $sheet->setCellValue("A{$row}", ExcelDate::PHPToExcel(Carbon::parse($day['date'])->startOfDay()));
            $sheet->fromArray([[
                $day['count'],
                $day[$digital]['count'],
                $day[$digital]['gross'],
                $day[$digital]['net'],
                $day[$physical]['count'],
                $day[$physical]['gross'],
                $day[$physical]['net'],
                $day['gross'],
                $day['net'],
            ]], null, "B{$row}", true);
            $row++;
        }

        $lastDataRow = max($row - 1, 3);
        $sheet->setCellValue("A{$row}", 'Total del período');
        foreach (range('B', 'J') as $column) {
            $sheet->setCellValue("{$column}{$row}", "=SUM({$column}3:{$column}{$lastDataRow})");
        }
        $this->styleSection($sheet, "A{$row}:J{$row}");

        $money = $this->moneyFormat();
        $sheet->getStyle("A3:A{$row}")->getNumberFormat()->setFormatCode(self::DATE_FORMAT);
        foreach (['B', 'C', 'F'] as $column) {
            $sheet->getStyle("{$column}3:{$column}{$row}")->getNumberFormat()->setFormatCode(self::COUNT_FORMAT);
        }
        foreach (['D', 'E', 'G', 'H', 'I', 'J'] as $column) {
            $sheet->getStyle("{$column}3:{$column}{$row}")->getNumberFormat()->setFormatCode($money);
        }
        $this->styleBorders($sheet, "A2:J{$row}");

        $sheet->setCellValue('A' . ($row + 2), 'Los netos son el monto luego de los descuentos aplicables a cada canal.');
        $sheet->mergeCells('A' . ($row + 2) . ':J' . ($row + 2));

        $this->setColumnWidths($sheet, [
            'A' => 21,
            'B' => 15,
            'C' => 15,
            'D' => 23,
            'E' => 23,
            'F' => 15,
            'G' => 24,
            'H' => 22,
            'I' => 24,
            'J' => 26,
        ]);
    }
}
