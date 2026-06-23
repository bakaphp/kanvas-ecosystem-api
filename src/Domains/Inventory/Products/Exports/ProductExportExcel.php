<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Products\Exports;

use Baka\Http\SafeUrlFetcher;
use Carbon\Carbon;
use Exception;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Kanvas\Inventory\Products\Models\Products;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use Maatwebsite\Excel\Concerns\WithCustomStartCell;
use Maatwebsite\Excel\Concerns\WithDrawings;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Events\AfterSheet;
use Maatwebsite\Excel\Events\BeforeSheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class ProductExportExcel implements FromQuery, WithMapping, WithDrawings, WithColumnWidths, WithStyles, WithEvents, WithCustomStartCell, ShouldAutoSize, WithChunkReading
{
    private array $data;
    private ?Builder $query;
    private int $headerRowStart;
    private int $dataRowStart;
    private string $timezone;

    public function __construct(array $data, ?Builder $query = null, ?string $timezone = null)
    {
        $this->data = $data;
        $this->query = $query;
        $this->timezone = $timezone ?? config('app.timezone', 'UTC');
        $this->headerRowStart = 5;
        $this->dataRowStart = ! empty($this->data['header_info']['subtitle']) ? 9 : 8;
    }

    public function query(): Builder
    {
        return $this->query ?? Products::query();
    }

    public function chunkSize(): int
    {
        return 500;
    }

    public function map($product): array
    {
        $row = [];
        foreach ($this->data['field_paths'] as $fieldPath) {
            $row[] = $this->getNestedValue($product, $fieldPath);
        }

        return $row;
    }

    public function startCell(): string
    {
        $startRow = 6;

        if (! empty($this->data['header_info']['subtitle'])) {
            $startRow++;
        }

        $startRow++;
        $startRow++;
        $startRow++;
        $startRow++;
        $startRow++;

        return 'A' . $startRow;
    }

    private function getNestedValue($object, string $path): mixed
    {
        if (Str::contains($path, ' ')) {
            return Str::of($path)
                ->explode(' ')
                ->map(fn ($singlePath) => $this->getNestedValue($object, $singlePath))
                ->filter()
                ->join(' ');
        }

        if (Str::startsWith($path, 'attribute.')) {
            return $this->getAttributeValueBySlug($object, Str::after($path, 'attribute.'));
        }

        if (Str::contains($path, '[')) {
            return $this->getArrayValue($object, $path);
        }

        $keys = explode('.', $path);
        $value = $object;

        foreach ($keys as $key) {
            if (is_object($value)) {
                if (method_exists($value, 'relationLoaded') && $value->relationLoaded($key)) {
                    $value = $value->getRelation($key);
                } elseif (method_exists($value, $key)) {
                    $value = $value->$key();
                } elseif (property_exists($value, $key)) {
                    $value = $value->$key;
                } elseif (method_exists($value, 'getAttribute')) {
                    $value = $value->getAttribute($key);
                } elseif (method_exists($value, '__get')) {
                    try {
                        $value = $value->__get($key);
                    } catch (Exception) {
                        $value = null;
                    }
                } else {
                    $value = $value->{$key} ?? null;
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

        if ($value instanceof Carbon) {
            return $this->formatDateWithTimezone($value, $path);
        }

        return $value;
    }

    private function getAttributeValueBySlug($product, string $slug): mixed
    {
        if (! method_exists($product, 'relationLoaded')) {
            return null;
        }

        $attributes = $product->relationLoaded('attributes')
            ? $product->getRelation('attributes')
            : $product->attributes()->get();

        foreach ($attributes as $attr) {
            $attrSlug = $attr->slug ?? ($attr->attribute->slug ?? null);
            if ($attrSlug === $slug) {
                $value = $attr->value;

                return is_array($value) ? (reset($value) ?: null) : $value;
            }
        }

        return null;
    }

    private function getArrayValue($object, string $path): mixed
    {
        preg_match_all('/([^.\[]+)(\[(\d+)\])?/', $path, $matches);
        $value = $object;

        for ($i = 0; $i < count($matches[1]); $i++) {
            $key = $matches[1][$i];
            $index = $matches[3][$i] ?? null;

            if (is_object($value)) {
                if (method_exists($value, 'relationLoaded') && $value->relationLoaded($key)) {
                    $value = $value->getRelation($key);
                } elseif (method_exists($value, $key)) {
                    $value = $value->$key();
                } elseif (property_exists($value, $key)) {
                    $value = $value->$key;
                } elseif (method_exists($value, 'getAttribute')) {
                    $value = $value->getAttribute($key);
                } elseif (method_exists($value, '__get')) {
                    try {
                        $value = $value->__get($key);
                    } catch (Exception) {
                        $value = null;
                    }
                } else {
                    $value = $value->{$key} ?? null;
                }
            } elseif (is_array($value)) {
                $value = $value[$key] ?? null;
            } else {
                return null;
            }

            if ($index !== null && $index !== '') {
                if (is_array($value)) {
                    $value = $value[$index] ?? null;
                } elseif ($value instanceof Collection || $value instanceof EloquentCollection) {
                    $value = $value->get($index);
                } else {
                    return null;
                }
            }

            if ($value === null) {
                return null;
            }
        }

        if ($value instanceof Carbon) {
            return $this->formatDateWithTimezone($value, $path);
        }

        return $value;
    }

    private function formatDateWithTimezone(Carbon $date, string $fieldPath): string
    {
        $dateFields = ['created_at', 'updated_at'];

        if (in_array(basename($fieldPath), $dateFields)) {
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
                    $imageContent = SafeUrlFetcher::fetch($logoUrl);
                    if ($imageContent !== '') {
                        $tempPath = storage_path('app/temp_logo_' . $index . '.png');
                        file_put_contents($tempPath, $imageContent);

                        $drawing = new Drawing();
                        $drawing->setName('Logo ' . ($index + 1));
                        $drawing->setDescription('Header Logo');
                        $drawing->setPath($tempPath);
                        $drawing->setHeight(50);

                        $columnIndex = (int) ($index * $columnsPerLogo);
                        $actualColumnIndex = (int) min($columnIndex, count($this->data['headers']) - 1);
                        $drawing->setCoordinates($this->getExcelColumn($actualColumnIndex) . '2');

                        $drawings[] = $drawing;
                    }
                } catch (Exception) {
                    continue;
                }
            }
        }

        return $drawings;
    }

    public function columnWidths(): array
    {
        $widths = [];
        foreach ($this->data['headers'] as $index => $header) {
            $widths[$this->getExcelColumn((int) $index)] = $this->getColumnWidth($header);
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

    private function getColumnWidth(string $header): float
    {
        $lower = strtolower($header);

        if (strpos($lower, 'fecha') !== false || strpos($lower, 'date') !== false) {
            return 18;
        } elseif (strpos($lower, 'monto') !== false || strpos($lower, 'amount') !== false) {
            return 15;
        } elseif (strpos($lower, 'numero') !== false || strpos($lower, 'number') !== false) {
            return 15;
        } elseif (strpos($lower, 'placa') !== false || strpos($lower, 'plate') !== false) {
            return 12;
        }

        return max(12, min(25, strlen($header) + 3));
    }

    public function styles(Worksheet $sheet): array
    {
        return [];
    }

    public function registerEvents(): array
    {
        return [
            BeforeSheet::class => function (BeforeSheet $event) {
                $this->insertHeaderRows($event->sheet->getDelegate());
            },
            AfterSheet::class => function (AfterSheet $event) {
                $this->applyHeaderStyling($event->sheet->getDelegate());

                for ($i = 0; $i < count($this->data['headers']); $i++) {
                    $event->sheet->getDelegate()->getColumnDimension($this->getExcelColumn($i))->setAutoSize(false);
                }
            },
        ];
    }

    private function insertHeaderRows($sheet): void
    {
        for ($row = 1; $row <= 4; $row++) {
            for ($col = 1; $col <= count($this->data['headers']); $col++) {
                $sheet->setCellValue([$col, $row], '');
            }
        }

        $sheet->setCellValue('A5', strtoupper($this->data['header_info']['title']));

        $currentRow = 6;

        if (! empty($this->data['header_info']['subtitle'])) {
            $sheet->setCellValue('A' . $currentRow, $this->data['header_info']['subtitle']);
            $currentRow++;
        }

        $sheet->setCellValue('A' . $currentRow, $this->data['header_info']['date_range']);
        $currentRow++;

        $sheet->setCellValue('A' . $currentRow, $this->data['header_info']['summary_line']);
        $currentRow++;

        for ($col = 1; $col <= count($this->data['headers']); $col++) {
            $sheet->setCellValue([$col, $currentRow], '');
        }
        $currentRow++;

        foreach ($this->data['headers'] as $index => $header) {
            $sheet->setCellValue([$index + 1, $currentRow], $header);
        }

        $this->dataRowStart = $currentRow + 1;
    }

    private function applyHeaderStyling($sheet): void
    {
        $lastColumn = $this->getExcelColumn((int) (count($this->data['headers']) - 1));
        $titleRow = 5;
        $headerRow = $this->dataRowStart - 1;

        $currentRow = 6;

        $sheet->getStyle('A' . $titleRow . ':' . $lastColumn . $titleRow)
            ->getFont()->setBold(true)->setSize(14);
        $sheet->getStyle('A' . $titleRow . ':' . $lastColumn . $titleRow)
            ->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->mergeCells('A' . $titleRow . ':' . $lastColumn . $titleRow);

        if (! empty($this->data['header_info']['subtitle'])) {
            $sheet->getStyle('A' . $currentRow . ':' . $lastColumn . $currentRow)
                ->getFont()->setBold(false)->setSize(12);
            $sheet->getStyle('A' . $currentRow . ':' . $lastColumn . $currentRow)
                ->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $sheet->mergeCells('A' . $currentRow . ':' . $lastColumn . $currentRow);
            $currentRow++;
        }

        $sheet->getStyle('A' . $currentRow . ':' . $lastColumn . $currentRow)
            ->getFont()->setBold(false)->setSize(10);
        $sheet->getStyle('A' . $currentRow . ':' . $lastColumn . $currentRow)
            ->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->mergeCells('A' . $currentRow . ':' . $lastColumn . $currentRow);
        $currentRow++;

        $sheet->getStyle('A' . $currentRow . ':' . $lastColumn . $currentRow)
            ->getFont()->setBold(false)->setSize(10);
        $sheet->getStyle('A' . $currentRow . ':' . $lastColumn . $currentRow)
            ->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->mergeCells('A' . $currentRow . ':' . $lastColumn . $currentRow);

        $sheet->getStyle('A' . $headerRow . ':' . $lastColumn . $headerRow)
            ->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
        $sheet->getStyle('A' . $headerRow . ':' . $lastColumn . $headerRow)
            ->getFill()->setFillType(Fill::FILL_SOLID)
            ->getStartColor()->setRGB('5D8A66');
        $sheet->getStyle('A' . $headerRow . ':' . $lastColumn . $headerRow)
            ->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        $sheet->getRowDimension(2)->setRowHeight(60);
        $sheet->getRowDimension($headerRow)->setRowHeight(25);
    }
}
