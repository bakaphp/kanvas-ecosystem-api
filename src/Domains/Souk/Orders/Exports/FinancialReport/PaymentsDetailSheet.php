<?php

declare(strict_types=1);

namespace Kanvas\Souk\Orders\Exports\FinancialReport;

use Kanvas\Souk\Orders\Exports\FinancialReport\Concerns\StylesFinancialReportSheet;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;
use Override;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class PaymentsDetailSheet implements FromArray, WithTitle, WithEvents
{
    use StylesFinancialReportSheet;

    private const string DETAIL_HEADER_FILL = '073763';

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
        return 'Detalle por transacción';
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => fn (AfterSheet $event) => $this->build($event->sheet->getDelegate()),
        ];
    }

    private function build(Worksheet $sheet): void
    {
        $sheet->setCellValue('A1', 'Listado de transacciones');
        $sheet->mergeCells('A1:E1');
        $this->styleSection($sheet, 'A1:E1');

        $sheet->fromArray([[
            'Fecha de pago',
            'Número de orden',
            $this->moneyLabel('Monto'),
            'Tipo de pago',
            'Estatus de la orden',
        ]], null, 'A2');
        $this->styleHeader($sheet, 'A2:E2', self::DETAIL_HEADER_FILL);

        $row = 3;
        foreach ($this->report['transactions'] as $transaction) {
            $sheet->setCellValue("A{$row}", ExcelDate::PHPToExcel($transaction['paid_at']->copy()->startOfDay()));
            $sheet->setCellValueExplicit("B{$row}", $transaction['order_number'], DataType::TYPE_STRING);
            $sheet->setCellValue("C{$row}", $transaction['amount']);
            $sheet->setCellValue("D{$row}", PaymentsFinancialReportLabels::channel($transaction['channel']));
            $sheet->setCellValue("E{$row}", $transaction['status']);
            $row++;
        }

        $lastRow = max($row - 1, 2);
        $sheet->getStyle("A3:A{$lastRow}")->getNumberFormat()->setFormatCode(self::DATE_FORMAT);
        $sheet->getStyle("C3:C{$lastRow}")->getNumberFormat()->setFormatCode($this->moneyFormat());
        $this->styleBorders($sheet, "A2:E{$lastRow}");

        $this->setColumnWidths($sheet, ['A' => 23, 'B' => 24, 'C' => 23, 'D' => 24, 'E' => 30]);
    }
}
