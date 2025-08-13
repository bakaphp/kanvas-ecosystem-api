<?php

declare(strict_types=1);

namespace Kanvas\Souk\Orders\Exports;

use Illuminate\Database\Eloquent\Builder;
use Kanvas\Souk\Orders\Models\Order;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use Maatwebsite\Excel\Concerns\WithCustomStartCell;
use Maatwebsite\Excel\Concerns\WithDrawings;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class OrderExportExcel implements FromQuery, WithMapping, WithDrawings, WithColumnWidths, WithStyles, WithEvents, WithCustomStartCell, ShouldAutoSize, WithChunkReading
{
    private $data;
    private $query;
    private $headerRowStart;
    private $dataRowStart;
    private $timezone;

    public function __construct($data, $query = null, $timezone = null)
    {
        $this->data = $data;
        $this->query = $query;
        $this->timezone = $timezone ?? config('app.timezone', 'UTC');
        $this->headerRowStart = 5; // Row where main title starts
        $this->dataRowStart = ! empty($this->data['header_info']['subtitle']) ? 9 : 8; // Row where data table starts
    }

    public function query(): Builder
    {
        return $this->query ?? Order::query();
    }

    public function chunkSize(): int
    {
        return 500; // Process in chunks of 500 rows
    }

    public function map($order): array
    {
        $row = [];
        foreach ($this->data['field_paths'] as $fieldPath) {
            $value = $this->getNestedValue($order, $fieldPath);
            $row[] = $value;
        }
        return $row;
    }


    public function startCell(): string
    {
        // Calculate the starting row dynamically - datos empiezan después de los headers
        $startRow = 6; // Base: logos (1-4) + title (5) + primera fila info (6)

        // Add subtitle row if exists
        if (! empty($this->data['header_info']['subtitle'])) {
            $startRow++;
        }

        // Add date range row
        $startRow++;

        // Add status filter row
        $startRow++;

        // Add empty row
        $startRow++;

        // Add column headers row
        $startRow++;

        // Now the actual data starts here
        $startRow++;

        return 'A' . $startRow;
    }

    private function getNestedValue($object, string $path)
    {
        // Handle array access like items[0].product_name
        if (strpos($path, '[') !== false) {
            return $this->getArrayValue($object, $path);
        }

        // Handle dot notation like orderStatus.name
        $keys = explode('.', $path);
        $value = $object;

        foreach ($keys as $key) {
            if (is_object($value)) {
                // Map common aliases to actual relationship names
                $actualKey = $this->mapRelationshipAlias($key);

                // Try Laravel relationship access first
                if (method_exists($value, 'relationLoaded') && $value->relationLoaded($actualKey)) {
                    $value = $value->getRelation($actualKey);
                } elseif (method_exists($value, $actualKey)) {
                    $value = $value->$actualKey();
                } elseif (property_exists($value, $actualKey)) {
                    $value = $value->$actualKey;
                } elseif (method_exists($value, 'getAttribute')) {
                    // Laravel model attribute access
                    $value = $value->getAttribute($actualKey);
                } elseif (method_exists($value, '__get')) {
                    // Try magic getter for Laravel relationships
                    try {
                        $value = $value->__get($actualKey);
                    } catch (\Exception $e) {
                        $value = null;
                    }
                } else {
                    // Try direct property access as last resort
                    $value = $value->{$actualKey} ?? null;
                }
            } elseif (is_array($value)) {
                $value = $value[$key] ?? null;
            } else {
                return null;
            }

            if ($value === null) {
                return null;
            }
        }

        // Format dates if it's a Carbon instance
        if ($value instanceof \Carbon\Carbon) {
            return $this->formatDateWithTimezone($value, $path);
        }

        return $value;
    }

    private function mapRelationshipAlias(string $key): string
    {
        // Map common aliases to actual relationship names
        $aliases = [
            'items' => 'allItems',
            // Add more aliases as needed
        ];

        return $aliases[$key] ?? $key;
    }

    private function getArrayValue($object, string $path)
    {
        // Parse path like "items[0].product_name"
        preg_match_all('/([^.\[]+)(\[(\d+)\])?/', $path, $matches);

        $value = $object;

        for ($i = 0; $i < count($matches[1]); $i++) {
            $key = $matches[1][$i];
            $index = $matches[3][$i] ?? null;

            // Get the property/method/relationship
            if (is_object($value)) {
                // Map common aliases to actual relationship names
                $actualKey = $this->mapRelationshipAlias($key);

                // Try Laravel relationship access first
                if (method_exists($value, 'relationLoaded') && $value->relationLoaded($actualKey)) {
                    $value = $value->getRelation($actualKey);
                } elseif (method_exists($value, $actualKey)) {
                    $value = $value->$actualKey();
                } elseif (property_exists($value, $actualKey)) {
                    $value = $value->$actualKey;
                } elseif (method_exists($value, 'getAttribute')) {
                    $value = $value->getAttribute($actualKey);
                } elseif (method_exists($value, '__get')) {
                    // Try magic getter for Laravel relationships
                    try {
                        $value = $value->__get($actualKey);
                    } catch (\Exception $e) {
                        $value = null;
                    }
                } else {
                    // Try direct property access
                    $value = $value->{$actualKey} ?? null;
                }
            } elseif (is_array($value)) {
                $value = $value[$key] ?? null;
            } else {
                return null;
            }

            // Apply array index if specified
            if ($index !== null && $index !== '') {
                if (is_array($value)) {
                    $value = $value[$index] ?? null;
                } elseif ($value instanceof \Illuminate\Support\Collection) {
                    $value = $value->get($index);
                } elseif ($value instanceof \Illuminate\Database\Eloquent\Collection) {
                    $value = $value->get($index);
                } else {
                    return null;
                }
            }

            if ($value === null) {
                return null;
            }
        }

        // Format dates if it's a Carbon instance
        if ($value instanceof \Carbon\Carbon) {
            return $this->formatDateWithTimezone($value, $path);
        }

        return $value;
    }

    private function formatDateWithTimezone(\Carbon\Carbon $date, string $fieldPath): string
    {
        // List of date fields that should be converted to user timezone
        $dateFields = ['created_at', 'updated_at', 'estimate_shipping_date', 'shipped_date'];

        // Extract the field name from the path (e.g., 'created_at' from 'order.created_at')
        $fieldName = basename($fieldPath);

        if (in_array($fieldName, $dateFields)) {
            // Convert to user's timezone
            $date = $date->setTimezone($this->timezone);
        }

        return $date->format('Y-m-d H:i:s');
    }

    public function drawings(): array
    {
        $drawings = [];

        if (! empty($this->data['header_info']['logos'])) {
            $logoCount = count($this->data['header_info']['logos']);
            $columnsPerLogo = (int) max(1, floor(count($this->data['headers']) / $logoCount));

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
                        $columnIndex = (int) ($index * $columnsPerLogo);
                        $actualColumnIndex = (int) min($columnIndex, count($this->data['headers']) - 1);
                        $column = $this->getExcelColumn($actualColumnIndex);
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
            $column = $this->getExcelColumn((int) $index); // Properly handle columns beyond Z
            $widths[$column] = $this->getColumnWidth($header);
        }

        return $widths;
    }

    private function getExcelColumn(int $index): string
    {
        $column = '';
        while ($index >= 0) {
            $column = chr(($index % 26) + 65) . $column;
            $index = intval($index / 26) - 1;
        }
        return $column;
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
        $lastColumn = $this->getExcelColumn((int) (count($this->data['headers']) - 1));
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
            \Maatwebsite\Excel\Events\BeforeSheet::class => function (\Maatwebsite\Excel\Events\BeforeSheet $event) {
                // Insert header rows before the data
                $this->insertHeaderRows($event->sheet->getDelegate());
            },
            \Maatwebsite\Excel\Events\AfterSheet::class => function (\Maatwebsite\Excel\Events\AfterSheet $event) {
                $lastColumn = $this->getExcelColumn((int) (count($this->data['headers']) - 1));

                // Apply styling to header rows and data
                $this->applyHeaderStyling($event->sheet->getDelegate());

                // Auto-fit columns - iterate through all columns needed
                for ($i = 0; $i < count($this->data['headers']); $i++) {
                    $column = $this->getExcelColumn($i);
                    $event->sheet->getDelegate()->getColumnDimension($column)->setAutoSize(false);
                }
            },
        ];
    }

    private function insertHeaderRows($sheet): void
    {
        // Insert empty rows for logos (rows 1-4)
        for ($row = 1; $row <= 4; $row++) {
            for ($col = 1; $col <= count($this->data['headers']); $col++) {
                $sheet->setCellValueByColumnAndRow($col, $row, '');
            }
        }

        // Main title row (row 5) - centered
        $sheet->setCellValue('A5', strtoupper($this->data['header_info']['title']));

        $currentRow = 6;

        // Subtitle row if exists
        if (! empty($this->data['header_info']['subtitle'])) {
            $sheet->setCellValue('A' . $currentRow, $this->data['header_info']['subtitle']);
            $currentRow++;
        }

        // Date range row - centered
        $sheet->setCellValue('A' . $currentRow, $this->data['header_info']['date_range']);
        $currentRow++;

        // Status filter row - centered
        $sheet->setCellValue('A' . $currentRow, $this->data['header_info']['status_filter']);
        $currentRow++;

        // Empty row before column headers
        for ($col = 1; $col <= count($this->data['headers']); $col++) {
            $sheet->setCellValueByColumnAndRow($col, $currentRow, '');
        }
        $currentRow++;

        // Column headers row
        foreach ($this->data['headers'] as $index => $header) {
            $sheet->setCellValueByColumnAndRow($index + 1, $currentRow, $header);
        }

        // Update the data starting position
        $this->dataRowStart = $currentRow + 1;
    }

    private function applyHeaderStyling($sheet): void
    {
        $lastColumn = $this->getExcelColumn((int) (count($this->data['headers']) - 1));
        $titleRow = 5;
        $headerRow = $this->dataRowStart - 1; // Column headers row

        $currentRow = 6;

        // Title styling - CENTRADO y negrita
        $sheet->getStyle('A' . $titleRow . ':' . $lastColumn . $titleRow)
            ->getFont()->setBold(true)->setSize(14);
        $sheet->getStyle('A' . $titleRow . ':' . $lastColumn . $titleRow)
            ->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);

        // Merge cells for title
        $sheet->mergeCells('A' . $titleRow . ':' . $lastColumn . $titleRow);

        // Subtitle styling if exists - CENTRADO
        if (! empty($this->data['header_info']['subtitle'])) {
            $sheet->getStyle('A' . $currentRow . ':' . $lastColumn . $currentRow)
                ->getFont()->setBold(false)->setSize(12);
            $sheet->getStyle('A' . $currentRow . ':' . $lastColumn . $currentRow)
                ->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
            // Merge cells for subtitle
            $sheet->mergeCells('A' . $currentRow . ':' . $lastColumn . $currentRow);
            $currentRow++;
        }

        // Date range styling - CENTRADO
        $sheet->getStyle('A' . $currentRow . ':' . $lastColumn . $currentRow)
            ->getFont()->setBold(false)->setSize(10);
        $sheet->getStyle('A' . $currentRow . ':' . $lastColumn . $currentRow)
            ->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
        // Merge cells for date range
        $sheet->mergeCells('A' . $currentRow . ':' . $lastColumn . $currentRow);
        $currentRow++;

        // Status filter styling - CENTRADO
        $sheet->getStyle('A' . $currentRow . ':' . $lastColumn . $currentRow)
            ->getFont()->setBold(false)->setSize(10);
        $sheet->getStyle('A' . $currentRow . ':' . $lastColumn . $currentRow)
            ->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
        // Merge cells for status filter
        $sheet->mergeCells('A' . $currentRow . ':' . $lastColumn . $currentRow);

        // Header row styling (column headers) - Verde con texto blanco
        $sheet->getStyle('A' . $headerRow . ':' . $lastColumn . $headerRow)
            ->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
        $sheet->getStyle('A' . $headerRow . ':' . $lastColumn . $headerRow)
            ->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
            ->getStartColor()->setRGB('5D8A66');
        $sheet->getStyle('A' . $headerRow . ':' . $lastColumn . $headerRow)
            ->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);

        // Set row heights
        $sheet->getRowDimension(2)->setRowHeight(60); // Logo row
        $sheet->getRowDimension($headerRow)->setRowHeight(25); // Header row
    }
};
