<?php

declare(strict_types=1);

namespace Kanvas\Souk\Orders\Exports;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class OrderPaymentsStatsExportExcel implements WithEvents, ShouldAutoSize
{
    private const HEADER_BG    = '2B4C8C';
    private const HEADER_FG    = 'FFFFFF';
    private const TOTAL_BG     = '2B4C8C';
    private const BORDER_COLOR = 'CCCCCC';

    /** @var array<string, array<string, string>> */
    private const LABELS = [
        'en' => [
            'breakdown_title'        => 'Payment Breakdown by Period',
            'breakdown_period'       => 'Period',
            'breakdown_transactions' => 'Transactions',
            'breakdown_amount'       => 'Total Amount',
            'breakdown_total'        => 'Total:',
            'metrics_title'          => 'Metrics',
            'metrics_metric'         => 'Metric',
            'metrics_value'          => 'Value',
            'metric_total_tx'        => 'Total transactions',
            'metric_total_amount'    => 'Total amount',
            'metric_daily_tx'        => 'Average daily transactions',
            'metric_daily_amount'    => 'Average daily amount',
            'metric_weekly_tx'       => 'Average weekly transactions',
            'metric_weekly_amount'   => 'Average weekly amount',
            'metric_monthly_tx'      => 'Average monthly transactions',
            'metric_monthly_amount'  => 'Average monthly amount',
            'detail_title'           => 'Order Detail',
            'detail_order_number'    => 'Order Number',
            'detail_date'            => 'Date',
            'detail_name'            => 'Name',
            'detail_amount'          => 'Amount',
            'detail_email'           => 'Email',
        ],
        'es' => [
            'breakdown_title'        => 'Resumen por Período',
            'breakdown_period'       => 'Período',
            'breakdown_transactions' => 'Transacciones',
            'breakdown_amount'       => 'Monto Total',
            'breakdown_total'        => 'Total:',
            'metrics_title'          => 'Métricas',
            'metrics_metric'         => 'Métrica',
            'metrics_value'          => 'Valor',
            'metric_total_tx'        => 'Total de transacciones',
            'metric_total_amount'    => 'Monto total',
            'metric_daily_tx'        => 'Promedio de transacciones diarias',
            'metric_daily_amount'    => 'Monto promedio diario',
            'metric_weekly_tx'       => 'Promedio de transacciones semanales',
            'metric_weekly_amount'   => 'Monto promedio semanal',
            'metric_monthly_tx'      => 'Promedio de transacciones mensuales',
            'metric_monthly_amount'  => 'Monto promedio mensual',
            'detail_title'           => 'Detalle de Órdenes',
            'detail_order_number'    => 'Numero de Orden',
            'detail_date'            => 'Fecha',
            'detail_name'            => 'Nombre',
            'detail_amount'          => 'Monto',
            'detail_email'           => 'Email',
        ],
    ];

    public function __construct(
        private readonly array $stats,
        private readonly Collection $orders,
        private readonly string $timezone = 'UTC',
        private readonly ?array $fieldMapper = null,
        private readonly string $language = 'en',
    ) {
    }

    private function label(string $key): string
    {
        return self::LABELS[$this->language][$key] ?? self::LABELS['en'][$key] ?? $key;
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $sheet = $event->sheet->getDelegate();
                $row   = 1;

                $row = $this->writeBreakdownSection($sheet, $row);
                $row++;
                $row = $this->writeMetricsSection($sheet, $row);
                $row++;
                $this->writeDetailSection($sheet, $row);

                $sheet->getColumnDimension('A')->setWidth(22);
                $sheet->getColumnDimension('B')->setWidth(18);
                $sheet->getColumnDimension('C')->setWidth(22);
                $sheet->getColumnDimension('D')->setWidth(15);
                $sheet->getColumnDimension('E')->setWidth(20);
                $sheet->getColumnDimension('F')->setWidth(35);
            },
        ];
    }

    private function writeBreakdownSection(Worksheet $sheet, int $startRow): int
    {
        $row     = $startRow;
        $lastCol = 'C';

        $sheet->setCellValue("A{$row}", $this->label('breakdown_title'));
        $sheet->mergeCells("A{$row}:{$lastCol}{$row}");
        $this->applyTitleStyle($sheet, "A{$row}:{$lastCol}{$row}");
        $row++;

        $sheet->setCellValue("A{$row}", $this->label('breakdown_period'));
        $sheet->setCellValue("B{$row}", $this->label('breakdown_transactions'));
        $sheet->setCellValue("C{$row}", $this->label('breakdown_amount'));
        $this->applyHeaderStyle($sheet, "A{$row}:{$lastCol}{$row}");
        $row++;

        foreach ($this->stats['byPeriod'] ?? [] as $item) {
            $sheet->setCellValue("A{$row}", $item['label']);
            $sheet->setCellValue("B{$row}", $item['total_transactions']);
            $sheet->setCellValue("C{$row}", '$' . number_format($item['total_amount'], 2));
            $this->applyDataStyle($sheet, "A{$row}:{$lastCol}{$row}");
            $sheet->getStyle("B{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $sheet->getStyle("C{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
            $row++;
        }

        $totals = $this->stats['totals'];
        $sheet->setCellValue("A{$row}", $this->label('breakdown_total'));
        $sheet->setCellValue("B{$row}", $totals['total_transactions']);
        $sheet->setCellValue("C{$row}", '$' . number_format($totals['total_amount'], 2));
        $this->applyTotalRowStyle($sheet, "A{$row}:{$lastCol}{$row}");
        $sheet->getStyle("A{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        $sheet->getStyle("B{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle("C{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);

        $this->applyTableBorder($sheet, "A{$startRow}:{$lastCol}{$row}");

        return $row + 1;
    }

    private function writeMetricsSection(Worksheet $sheet, int $startRow): int
    {
        $row     = $startRow;
        $lastCol = 'B';

        $totals   = $this->stats['totals'];
        $byPeriod = collect($this->stats['periods'] ?? [])->keyBy('period_type');
        $daily    = $byPeriod->get('DAY', []);
        $weekly   = $byPeriod->get('WEEK', []);
        $monthly  = $byPeriod->get('MONTH', []);

        $sheet->setCellValue("A{$row}", $this->label('metrics_metric'));
        $sheet->setCellValue("B{$row}", $this->label('metrics_value'));
        $this->applyHeaderStyle($sheet, "A{$row}:{$lastCol}{$row}");
        $row++;

        $metrics = [
            [$this->label('metric_total_tx'),       $totals['total_transactions']],
            [$this->label('metric_total_amount'),   '$' . number_format($totals['total_amount'], 2)],
            [$this->label('metric_daily_tx'),       number_format($daily['avg_count'] ?? 0, 2)],
            [$this->label('metric_daily_amount'),   '$' . number_format($daily['amount_avg'] ?? 0, 2)],
            [$this->label('metric_weekly_tx'),      number_format($weekly['avg_count'] ?? 0, 2)],
            [$this->label('metric_weekly_amount'),  '$' . number_format($weekly['amount_avg'] ?? 0, 2)],
            [$this->label('metric_monthly_tx'),     number_format($monthly['avg_count'] ?? 0, 2)],
            [$this->label('metric_monthly_amount'), '$' . number_format($monthly['amount_avg'] ?? 0, 2)],
        ];

        foreach ($metrics as [$label, $value]) {
            $sheet->setCellValue("A{$row}", $label);
            $sheet->setCellValue("B{$row}", $value);
            $this->applyDataStyle($sheet, "A{$row}:{$lastCol}{$row}");
            $sheet->getStyle("B{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
            $row++;
        }

        $this->applyTableBorder($sheet, "A{$startRow}:{$lastCol}" . ($row - 1));

        return $row;
    }

    private function writeDetailSection(Worksheet $sheet, int $startRow): int
    {
        $defaultMapper = [
            $this->label('detail_order_number') => 'order_number',
            $this->label('detail_date')         => 'created_at',
            $this->label('detail_name')         => 'user.firstname user.lastname',
            $this->label('detail_amount')       => 'total_net_amount',
            $this->label('detail_email')        => 'user.email',
        ];

        $mapper  = $this->fieldMapper ?? $defaultMapper;
        $headers = array_keys($mapper);
        $paths   = array_values($mapper);
        $lastCol = $this->columnLetter(count($headers) - 1);
        $row     = $startRow;

        $sheet->setCellValue("A{$row}", $this->label('detail_title'));
        $sheet->mergeCells("A{$row}:{$lastCol}{$row}");
        $this->applyTitleStyle($sheet, "A{$row}:{$lastCol}{$row}");
        $row++;

        foreach ($headers as $i => $header) {
            $sheet->setCellValueByColumnAndRow($i + 1, $row, $header);
        }
        $this->applyHeaderStyle($sheet, "A{$row}:{$lastCol}{$row}");
        $row++;

        foreach ($this->orders as $order) {
            foreach ($paths as $i => $path) {
                $value = $this->resolveFieldPath($order, $path);
                $sheet->setCellValueByColumnAndRow($i + 1, $row, $value);
            }
            $this->applyDataStyle($sheet, "A{$row}:{$lastCol}{$row}");
            $row++;
        }

        $this->applyTableBorder($sheet, "A{$startRow}:{$lastCol}" . ($row - 1));

        return $row;
    }

    private function resolveFieldPath(mixed $model, string $path): mixed
    {
        // Space-separated paths = concatenate values (e.g. "user.firstname user.lastname")
        if (Str::contains($path, ' ')) {
            return collect(explode(' ', $path))
                ->map(fn ($p) => $this->resolveFieldPath($model, $p))
                ->filter(fn ($v) => $v !== null && $v !== '')
                ->implode(' ');
        }

        $keys  = explode('.', $path);
        $value = $model;

        foreach ($keys as $key) {
            if ($value === null) {
                return null;
            }

            // Array index access: items[0]
            if (preg_match('/^(\w+)\[(\d+)\]$/', $key, $m)) {
                $value = $this->getAttribute($value, $m[1]);
                $value = is_array($value) ? ($value[(int) $m[2]] ?? null)
                    : (($value instanceof Collection) ? $value->get((int) $m[2]) : null);
                continue;
            }

            $value = $this->getAttribute($value, $key);
        }

        if ($value instanceof Carbon) {
            return $value->setTimezone($this->timezone)->format('d/m/Y');
        }

        return $value;
    }

    private function getAttribute(mixed $object, string $key): mixed
    {
        if (is_array($object)) {
            return $object[$key] ?? null;
        }

        if (is_object($object)) {
            // Eloquent attribute / relationship
            if (method_exists($object, 'getAttribute')) {
                return $object->getAttribute($key);
            }

            return $object->{$key} ?? null;
        }

        return null;
    }

    private function columnLetter(int $index): string
    {
        $letter = '';
        while ($index >= 0) {
            $letter = chr(($index % 26) + 65) . $letter;
            $index  = intdiv($index, 26) - 1;
        }

        return $letter;
    }

    private function applyTitleStyle(Worksheet $sheet, string $range): void
    {
        $sheet->getStyle($range)->applyFromArray([
            'font' => [
                'bold'  => true,
                'size'  => 13,
                'color' => ['rgb' => self::HEADER_FG],
            ],
            'fill' => [
                'fillType'   => Fill::FILL_SOLID,
                'startColor' => ['rgb' => self::HEADER_BG],
            ],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical'   => Alignment::VERTICAL_CENTER,
            ],
        ]);
        $sheet->getRowDimension($this->rowFromRange($range))->setRowHeight(22);
    }

    private function applyHeaderStyle(Worksheet $sheet, string $range): void
    {
        $sheet->getStyle($range)->applyFromArray([
            'font' => [
                'bold'  => true,
                'color' => ['rgb' => self::HEADER_FG],
            ],
            'fill' => [
                'fillType'   => Fill::FILL_SOLID,
                'startColor' => ['rgb' => self::HEADER_BG],
            ],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical'   => Alignment::VERTICAL_CENTER,
            ],
        ]);
        $sheet->getRowDimension($this->rowFromRange($range))->setRowHeight(20);
    }

    private function applyDataStyle(Worksheet $sheet, string $range): void
    {
        $sheet->getStyle($range)->applyFromArray([
            'fill' => [
                'fillType'   => Fill::FILL_SOLID,
                'startColor' => ['rgb' => 'FFFFFF'],
            ],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_LEFT,
                'vertical'   => Alignment::VERTICAL_CENTER,
            ],
        ]);
    }

    private function applyTotalRowStyle(Worksheet $sheet, string $range): void
    {
        $sheet->getStyle($range)->applyFromArray([
            'font' => [
                'bold'  => true,
                'color' => ['rgb' => self::HEADER_FG],
            ],
            'fill' => [
                'fillType'   => Fill::FILL_SOLID,
                'startColor' => ['rgb' => self::TOTAL_BG],
            ],
            'alignment' => [
                'vertical' => Alignment::VERTICAL_CENTER,
            ],
        ]);
    }

    private function applyTableBorder(Worksheet $sheet, string $range): void
    {
        $sheet->getStyle($range)->getBorders()->getAllBorders()->applyFromArray([
            'borderStyle' => Border::BORDER_THIN,
            'color'       => ['rgb' => self::BORDER_COLOR],
        ]);
    }

    private function rowFromRange(string $range): int
    {
        preg_match('/(\d+)/', $range, $m);

        return (int) ($m[1] ?? 1);
    }
}
