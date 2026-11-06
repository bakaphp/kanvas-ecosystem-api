<?php

declare(strict_types=1);

namespace Kanvas\Souk\Orders\Exports\FinancialReport;

use Kanvas\Souk\Orders\Exports\Concerns\DrawsHeaderLogos;
use Kanvas\Souk\Orders\Exports\FinancialReport\Concerns\StylesFinancialReportSheet;
use Kanvas\Souk\Payments\Enums\PaymentChannelEnum;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithDrawings;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;
use Override;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class PaymentsSummarySheet implements FromArray, WithTitle, WithEvents, WithDrawings
{
    use DrawsHeaderLogos;
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
        return 'Resumen de pagos';
    }

    public function drawings(): array
    {
        return $this->headerLogoDrawings($this->metadata['headerImages'] ?? []);
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => fn (AfterSheet $event) => $this->build($event->sheet->getDelegate()),
        ];
    }

    private function build(Worksheet $sheet): void
    {
        $row = empty($this->metadata['headerImages']) ? 1 : 4;
        $money = $this->moneyFormat();

        $sheet->setCellValue("A{$row}", (string) ($this->metadata['custom_title'] ?? 'Reporte financiero de pagos'));
        $sheet->mergeCells("A{$row}:E{$row}");
        $sheet->getStyle("A{$row}")->getFont()->setBold(true)->setSize(14);
        $row++;

        $sheet->setCellValue("A{$row}", 'Ubicación');
        $sheet->setCellValue("B{$row}", $this->report['location'] ?? 'Todas las ubicaciones');
        $sheet->mergeCells("B{$row}:E{$row}");
        $sheet->getStyle("A{$row}:B{$row}")->getFont()->setBold(true);
        $row++;

        $sheet->setCellValue("A{$row}", 'Rango de fechas');
        $sheet->setCellValue("B{$row}", 'Desde');
        $sheet->setCellValue("C{$row}", $this->report['start_date']);
        $sheet->setCellValue("D{$row}", 'Hasta');
        $sheet->setCellValue("E{$row}", $this->report['end_date']);
        $sheet->getStyle("A{$row}")->getFont()->setBold(true);
        $row++;

        $sheet->setCellValue("A{$row}", 'Tipo de pago');
        $sheet->setCellValue("B{$row}", PaymentsFinancialReportLabels::channelFilter($this->report['channel']));
        $sheet->mergeCells("B{$row}:E{$row}");
        $sheet->getStyle("A{$row}")->getFont()->setBold(true);
        $row += 2;

        $sheet->setCellValue("A{$row}", 'Resumen del total de pagos por método');
        $sheet->mergeCells("A{$row}:E{$row}");
        $this->styleSection($sheet, "A{$row}:E{$row}");
        $row++;

        $headerRow = $row;
        $sheet->fromArray([[
            'Método de pago',
            'Cantidad de pagos',
            $this->moneyLabel('Recaudación bruta'),
            $this->moneyLabel('Ticket promedio'),
            'Participación por monto',
        ]], null, "A{$row}");
        $this->styleHeader($sheet, "A{$row}:E{$row}");
        $row++;

        $firstDataRow = $row;
        $totalRow = $row + 2;

        foreach ([PaymentChannelEnum::DIGITAL, PaymentChannelEnum::PHYSICAL] as $channel) {
            $sheet->setCellValue("A{$row}", PaymentsFinancialReportLabels::summaryMethod($channel));
            $sheet->setCellValue("B{$row}", $this->report['summary'][$channel->value]['count']);
            $sheet->setCellValue("C{$row}", $this->report['summary'][$channel->value]['gross']);
            $sheet->setCellValue("D{$row}", "=IFERROR(C{$row}/B{$row},0)");
            $sheet->setCellValue("E{$row}", "=IFERROR(C{$row}/\$C\${$totalRow},0)");
            $row++;
        }

        $lastDataRow = $row - 1;
        $sheet->setCellValue("A{$row}", 'Total del período');
        $sheet->setCellValue("B{$row}", "=SUM(B{$firstDataRow}:B{$lastDataRow})");
        $sheet->setCellValue("C{$row}", "=SUM(C{$firstDataRow}:C{$lastDataRow})");
        $sheet->setCellValue("D{$row}", "=IFERROR(C{$row}/B{$row},0)");
        $sheet->setCellValue("E{$row}", "=IFERROR(C{$row}/\$C\${$totalRow},0)");
        $this->styleSection($sheet, "A{$row}:E{$row}");

        $sheet->getStyle("B{$firstDataRow}:B{$totalRow}")->getNumberFormat()->setFormatCode(self::COUNT_FORMAT);
        $sheet->getStyle("C{$firstDataRow}:D{$totalRow}")->getNumberFormat()->setFormatCode($money);
        $sheet->getStyle("E{$firstDataRow}:E{$totalRow}")->getNumberFormat()->setFormatCode(self::PERCENT_FORMAT);
        $this->styleBorders($sheet, "A{$headerRow}:E{$totalRow}");
        $row += 3;

        $sheet->setCellValue("A{$row}", 'Resumen de recaudación del período');
        $sheet->mergeCells("A{$row}:E{$row}");
        $this->styleSection($sheet, "A{$row}:E{$row}");
        $row++;

        $sheet->setCellValue("A{$row}", $this->moneyLabel('Recaudación bruta total'));
        $sheet->setCellValue("C{$row}", $this->netTotalLabel());
        $sheet->mergeCells("A{$row}:B{$row}");
        $sheet->mergeCells("C{$row}:E{$row}");
        $this->styleHeader($sheet, "A{$row}:E{$row}");
        $row++;

        $sheet->setCellValue("A{$row}", $this->report['summary']['gross']);
        $sheet->setCellValue("C{$row}", $this->report['summary']['net']);
        $sheet->mergeCells("A{$row}:B{$row}");
        $sheet->mergeCells("C{$row}:E{$row}");
        $sheet->getStyle("A{$row}:E{$row}")->getNumberFormat()->setFormatCode($money);
        $sheet->getStyle("A{$row}:E{$row}")->getFont()->setBold(true);
        $sheet->getStyle("A{$row}:E{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $this->styleBorders($sheet, 'A' . ($row - 1) . ":E{$row}");
        $row += 2;

        $sheet->setCellValue("A{$row}", 'La recaudación neta es el monto luego de los descuentos aplicables.');
        $sheet->mergeCells("A{$row}:E{$row}");

        $this->setColumnWidths($sheet, ['A' => 32, 'B' => 18, 'C' => 22, 'D' => 20, 'E' => 22]);
    }
}
