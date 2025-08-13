<?php

declare(strict_types=1);

namespace Kanvas\Souk\Orders\Exports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use Maatwebsite\Excel\Concerns\WithDrawings;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class OrderExportExcel implements FromCollection, WithDrawings, WithColumnWidths, WithStyles, WithEvents
{
    private $data;
    private $headerRowStart;
    private $dataRowStart;

    public function __construct($data)
    {
        $this->data = $data;
        $this->headerRowStart = 5; // Row where main title starts
        $this->dataRowStart = ! empty($this->data['header_info']['subtitle']) ? 9 : 8; // Row where data table starts
    }

    public function collection(): Collection
    {
        $collection = collect();

        // Add empty rows for logos
        for ($i = 0; $i < 4; $i++) {
            $collection->push(collect(array_fill(0, count($this->data['headers']), '')));
        }

        // Main title row (normal text, no background)
        $titleRow = collect(array_fill(0, count($this->data['headers']), ''));
        $titleRow[0] = strtoupper($this->data['header_info']['title']);
        $collection->push($titleRow);

        // Subtitle row (normal text, no background)
        if (! empty($this->data['header_info']['subtitle'])) {
            $subtitleRow = collect(array_fill(0, count($this->data['headers']), ''));
            $subtitleRow[0] = $this->data['header_info']['subtitle'];
            $collection->push($subtitleRow);
        }

        // Date range row (normal text, no background)
        $dateRangeRow = collect(array_fill(0, count($this->data['headers']), ''));
        $dateRangeRow[0] = $this->data['header_info']['date_range'];
        $collection->push($dateRangeRow);

        // Empty row
        $collection->push(collect(array_fill(0, count($this->data['headers']), '')));

        // Add column headers
        $collection->push(collect($this->data['headers']));

        // Add order data
        foreach ($this->data['orders'] as $order) {
            $collection->push(collect($order));
        }

        // Add summary row
        $summaryRow = collect(array_fill(0, count($this->data['headers']), ''));
        $summaryRow[0] = 'Total:';
        $summaryRow[1] = count($this->data['orders']);
        $collection->push($summaryRow);

        return $collection;
    }

    public function drawings(): array
    {
        $drawings = [];

        if (! empty($this->data['header_info']['logos'])) {
            $logoCount = count($this->data['header_info']['logos']);
            $columnsPerLogo = max(1, floor(count($this->data['headers']) / $logoCount));

            foreach ($this->data['header_info']['logos'] as $index => $logoUrl) {
                try {
                    $imageContent = file_get_contents($logoUrl);
                    if ($imageContent !== false) {
                        $tempPath = storage_path('app/temp_logo_' . $index . '.png');
                        file_put_contents($tempPath, $imageContent);

                        $drawing = new \PhpOffice\PhpSpreadsheet\Worksheet\Drawing();
                        $drawing->setName('Logo ' . ($index + 1));
                        $drawing->setDescription('Header Logo');
                        $drawing->setPath($tempPath);
                        $drawing->setHeight(50);

                        // Distribute logos across the header
                        $columnIndex = $index * $columnsPerLogo;
                        $number = 65 + min($columnIndex, count($this->data['headers']) - 1);
                        $number = (int) floor($number);
                        $column = chr($number);
                        $drawing->setCoordinates($column . '2');

                        $drawings[] = $drawing;
                    }
                } catch (\Exception $e) {
                    continue;
                }
            }
        }

        return $drawings;
    }

    public function columnWidths(): array
    {
        $widths = [];
        $headers = $this->data['headers'];

        foreach ($headers as $index => $header) {
            $column = chr(65 + $index); // A, B, C, etc.
            $widths[$column] = $this->getColumnWidth($header);
        }

        return $widths;
    }

    private function getColumnWidth($header): float
    {
        // Set widths based on content type
        $lowerHeader = strtolower($header);

        if (strpos($lowerHeader, 'fecha') !== false || strpos($lowerHeader, 'date') !== false) {
            return 18;
        } elseif (strpos($lowerHeader, 'monto') !== false || strpos($lowerHeader, 'amount') !== false) {
            return 15;
        } elseif (strpos($lowerHeader, 'numero') !== false || strpos($lowerHeader, 'number') !== false) {
            return 15;
        } elseif (strpos($lowerHeader, 'placa') !== false || strpos($lowerHeader, 'plate') !== false) {
            return 12;
        } else {
            return max(12, min(25, strlen($header) + 3));
        }
    }

    public function styles(Worksheet $sheet)
    {
        $lastColumn = chr(64 + count($this->data['headers']));
        $titleRow = 5;
        $subtitleRow = ! empty($this->data['header_info']['subtitle']) ? 6 : 0;
        $dateRangeRow = ! empty($this->data['header_info']['subtitle']) ? 7 : 6;
        $headerRow = ! empty($this->data['header_info']['subtitle']) ? 9 : 8;
        $lastDataRow = $headerRow + count($this->data['orders']);

        // Title styling (normal text, no background, left aligned)
        $sheet->getStyle('A' . $titleRow)
            ->getFont()->setBold(true)->setSize(12);
        $sheet->getStyle('A' . $titleRow)
            ->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_LEFT);

        // Subtitle styling (normal text, no background, left aligned)
        if ($subtitleRow > 0) {
            $sheet->getStyle('A' . $subtitleRow)
                ->getFont()->setBold(false)->setSize(10);
            $sheet->getStyle('A' . $subtitleRow)
                ->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_LEFT);
        }

        // Date range styling (normal text, no background, left aligned)
        $sheet->getStyle('A' . $dateRangeRow)
            ->getFont()->setBold(false)->setSize(10);
        $sheet->getStyle('A' . $dateRangeRow)
            ->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_LEFT);

        // Header row styling (ONLY the table header has color background)
        $sheet->getStyle('A' . $headerRow . ':' . $lastColumn . $headerRow)
            ->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
        $sheet->getStyle('A' . $headerRow . ':' . $lastColumn . $headerRow)
            ->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
            ->getStartColor()->setRGB('5D8A66'); // Green background only for headers
        $sheet->getStyle('A' . $headerRow . ':' . $lastColumn . $headerRow)
            ->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);

        // Data rows styling (WHITE background, left aligned)
        for ($row = $headerRow + 1; $row <= $lastDataRow; $row++) {
            $sheet->getStyle('A' . $row . ':' . $lastColumn . $row)
                ->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
                ->getStartColor()->setRGB('FFFFFF'); // White background
            $sheet->getStyle('A' . $row . ':' . $lastColumn . $row)
                ->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_LEFT);
        }

        // Summary row styling (green background)
        $summaryRow = $lastDataRow + 1;
        $sheet->getStyle('A' . $summaryRow . ':' . $lastColumn . $summaryRow)
            ->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
        $sheet->getStyle('A' . $summaryRow . ':' . $lastColumn . $summaryRow)
            ->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
            ->getStartColor()->setRGB('5D8A66'); // Green background
        $sheet->getStyle('A' . $summaryRow . ':' . $lastColumn . $summaryRow)
            ->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_LEFT);

        // Add borders to table data only
        $sheet->getStyle('A' . $headerRow . ':' . $lastColumn . $summaryRow)
            ->getBorders()->getAllBorders()
            ->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN)
            ->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('CCCCCC'));

        // Set row heights
        $sheet->getRowDimension(2)->setRowHeight(60); // Logo row
        $sheet->getRowDimension($headerRow)->setRowHeight(25); // Header row

        return [];
    }

    public function registerEvents(): array
    {
        return [
            \Maatwebsite\Excel\Events\AfterSheet::class => function (\Maatwebsite\Excel\Events\AfterSheet $event) {
                $lastColumn = chr(64 + count($this->data['headers']));

                // NO merge cells - keep everything left aligned and simple

                // Auto-fit columns
                foreach (range('A', $lastColumn) as $column) {
                    $event->sheet->getDelegate()->getColumnDimension($column)->setAutoSize(false);
                }
            },
        ];
    }
};
