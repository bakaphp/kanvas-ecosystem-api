<?php

declare(strict_types=1);

namespace Kanvas\Souk\Orders\Actions;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Knp\Snappy\Pdf;
use Maatwebsite\Excel\Facades\Excel;

class ExportOrdersAction
{
    public function __construct(
        protected Collection $orderData,
        protected ?array $fieldMapper = null,
        protected ?array $metadata = null,
        protected ?array $whereConditions = null
    ) {
    }

    public function execute(string $format): array
    {
       $timestamp = Carbon::now()->format('Y-m-d');
       $filename = "orders_export_{$timestamp}";

       $metaData = [
        "title" => $this->metadata['custom_title'] ?? 'REPORTE DE ÓRDENES',
        "subtitle" => $this->metadata['subtitle'] ?? '',
        "headerImages" => $this->metadata['headerImages'] ?? []
       ];
       
       if ($format === 'EXCEL') {
           return $this->toExcel($this->orderData, $filename, $metaData);
       } else if ($format === 'PDF') {
           return $this->toPdf($this->orderData, $filename, $metaData);
       }

       return [
        'status' => 'error',
        'download_url' => null,
        'file_name' => null,
        'message' => 'Invalid export format specified'
       ];
    }

    private function exportToExcel(array $data, array $headers, string $fileName): string
    {
        $filePath = storage_path('app/exports/' . $fileName);
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->fromArray($data);
        $writer = new Csv($spreadsheet);
        $writer->save($filePath);
    }

    private function exportToPdf(array $data, array $headers, array $logos, string $fileName = 'orders.pdf'): string
    {
        $html = $this->generatePdfHtml($data, $headers, $logos);
        
        $filePath = storage_path("app/exports/{$fileName}");

        $pdf = new Pdf();
        // Configure PDF options
        $pdf->setOptions([
            'page-size' => 'A4',
            'orientation' => 'landscape', // Better for tables
            'margin-top' => 10,
            'margin-right' => 10,
            'margin-bottom' => 10,
            'margin-left' => 10,
            'encoding' => 'UTF-8',
            'enable-local-file-access' => true
        ]);
        
        $pdf->generateFromHtml($html, $filePath);
        
        return $filePath;
    }

    protected function generatePdfHtml(array $data, array $headers, array $logos = []): string
    {
        $logoHtml = '';
        if (!empty($logos)) {
            $logoHtml = '<div class="logo-row">';
            foreach ($logos as $logo) {
                $logoHtml .= "<img src='{$logo}' class='logo' alt='Logo'>";
            }
            $logoHtml .= '</div>';
        }

        $headerCells = '';
        foreach ($headers as $header) {
            $headerCells .= "<th>{$header}</th>";
        }

        $dataRows = '';
        foreach ($data as $row) {
            $dataRows .= '<tr>';
            foreach ($row as $cell) {
                $dataRows .= "<td>{$cell}</td>";
            }
            $dataRows .= '</tr>';
        }

        return "
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset='UTF-8'>
            <style>
                body {
                    font-family: Arial, sans-serif;
                    margin: 0;
                    padding: 20px;
                }
                .header {
                    text-align: center;
                    margin-bottom: 20px;
                    border-bottom: 2px solid #333;
                    padding-bottom: 15px;
                }
                .logo-row {
                    display: flex;
                    justify-content: space-between;
                    align-items: center;
                    margin-bottom: 15px;
                }
                .logo {
                    max-height: 60px;
                    max-width: 150px;
                    object-fit: contain;
                }
                .company-info {
                    margin: 10px 0;
                }
                .company-info h1 {
                    margin: 0;
                    color: #333;
                    font-size: 24px;
                }
                .company-info p {
                    margin: 5px 0;
                    color: #666;
                }
                table {
                    width: 100%;
                    border-collapse: collapse;
                    margin-top: 20px;
                }
                th {
                    background-color: #f8f9fa;
                    border: 1px solid #ddd;
                    padding: 12px;
                    text-align: left;
                    font-weight: bold;
                    color: #333;
                }
                td {
                    border: 1px solid #ddd;
                    padding: 10px;
                    text-align: left;
                }
                tr:nth-child(even) {
                    background-color: #f9f9f9;
                }
                tr:hover {
                    background-color: #f5f5f5;
                }
                .footer {
                    margin-top: 30px;
                    text-align: center;
                    font-size: 12px;
                    color: #666;
                    border-top: 1px solid #ddd;
                    padding-top: 10px;
                }
            </style>
        </head>
        <body>
            <div class='header'>
                {$logoHtml}
                <div class='company-info'>
                    <h1>Data Export Report</h1>
                    <p>Generated on " . now()->format('F j, Y \a\t g:i A') . "</p>
                </div>
            </div>
            
            <table>
                <thead>
                    <tr>{$headerCells}</tr>
                </thead>
                <tbody>
                    {$dataRows}
                </tbody>
            </table>
            
            <div class='footer'>
                <p>This report contains " . count($data) . " records</p>
            </div>
        </body>
        </html>";
    }

    private function exportZip(array $data, array $headers, array $logos, string $fileName): string
    {
        $files = [
            'excel' => $this->exportToExcel($data, $headers, $fileName),
            'pdf' => $this->exportToPdf($data, $headers, $logos, $fileName),
        ];
        
        // Create a ZIP file with both exports
        $zip = new \ZipArchive();
        $zipPath = storage_path('app/exports/report.zip');
        
        if ($zip->open($zipPath, \ZipArchive::CREATE) === TRUE) {
            $zip->addFile($files['excel'], 'report.csv');
            $zip->addFile($files['pdf'], 'report.pdf');
            $zip->close();
        }

        return $zipPath;
    }

    private function toExcel($orders, string $filename, array $metaData = []): array
    {
        $data = $this->prepareOrderData($orders, $metaData);
        
        $export = new class($data) implements 
            \Maatwebsite\Excel\Concerns\FromCollection,
            \Maatwebsite\Excel\Concerns\WithDrawings,
            \Maatwebsite\Excel\Concerns\WithColumnWidths,
            \Maatwebsite\Excel\Concerns\WithStyles,
            \Maatwebsite\Excel\Concerns\WithEvents {
            
            private $data;
            private $headerRowStart;
            private $dataRowStart;
            
            public function __construct($data) {
                $this->data = $data;
                $this->headerRowStart = 5; // Row where main title starts
                $this->dataRowStart = !empty($this->data['header_info']['subtitle']) ? 9 : 8; // Row where data table starts
            }
            
            public function collection()
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
                if (!empty($this->data['header_info']['subtitle'])) {
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
            
            public function drawings()
            {
                $drawings = [];
                
                if (!empty($this->data['header_info']['logos'])) {
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
                                $column = chr(65 + min($columnIndex, count($this->data['headers']) - 1));
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
            
            public function styles(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet)
            {
                $lastColumn = chr(64 + count($this->data['headers']));
                $titleRow = 5;
                $subtitleRow = !empty($this->data['header_info']['subtitle']) ? 6 : 0;
                $dateRangeRow = !empty($this->data['header_info']['subtitle']) ? 7 : 6;
                $headerRow = !empty($this->data['header_info']['subtitle']) ? 9 : 8;
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
                    \Maatwebsite\Excel\Events\AfterSheet::class => function(\Maatwebsite\Excel\Events\AfterSheet $event) {
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

        $filePath = "exports/{$filename}.xlsx";
        Excel::store($export, $filePath, 'public');
        
        // Clean up temporary image files
        $this->cleanupTempImages();
        
        return [
            'status' => 'success',
            'download_url' => Storage::disk('public')->url($filePath),
            'file_name' => "{$filename}.xlsx",
            'message' => 'Excel export completed successfully'
        ];
    }
    
    private function cleanupTempImages(): void
    {
        $tempFiles = glob(storage_path('app/temp_logo_*.png'));
        foreach ($tempFiles as $file) {
            if (file_exists($file)) {
                unlink($file);
            }
        }
    }

    private function toPdf($orders, string $filename, array $metaData = []): array
    {
        $data = $this->prepareOrderData($orders, $metaData);
        
        // Generate HTML from the Blade view
        $html = view('exports.orders', ['orders' => $data])->render();
        
        // Create PDF using Knp\Snappy\Pdf
        // Try to find wkhtmltopdf binary in common locations
        $binaryPath = config('snappy.pdf.binary') ?? $this->findWkhtmltopdfBinary();
        $pdf = new Pdf($binaryPath);
        $pdf->setOptions([
            'page-size' => 'A4',
            'orientation' => 'landscape',
            'margin-top' => 10,
            'margin-right' => 10,
            'margin-bottom' => 10,
            'margin-left' => 10,
            'encoding' => 'UTF-8',
            'enable-local-file-access' => true
        ]);
        
        $pdfContent = $pdf->getOutputFromHtml($html);
        
        $filePath = "exports/{$filename}.pdf";
        Storage::disk('public')->put($filePath, $pdfContent);
        
        return [
            'status' => 'success',
            'download_url' => Storage::disk('public')->url($filePath),
            'file_name' => "{$filename}.pdf",
            'message' => 'PDF export completed successfully'
        ];
    }

    private function findWkhtmltopdfBinary(): string
    {
        $commonPaths = [
            '/usr/local/bin/wkhtmltopdf',
            '/usr/bin/wkhtmltopdf',
            '/bin/wkhtmltopdf',
            'wkhtmltopdf' // Let system find it in PATH
        ];

        foreach ($commonPaths as $path) {
            if ($path === 'wkhtmltopdf' || file_exists($path)) {
                return $path;
            }
        }

        throw new \Exception('wkhtmltopdf binary not found. Please install wkhtmltopdf or set the binary path in config.');
    }

    private function prepareOrderData($orders, array $metaData = []): array
    {
        $data = [];

        // Header information with logos and company details
        $headerInfo = [
            'logos' => $metaData['headerImages'] ?? [],
            'title' => $metaData['title'] ?? 'REPORTE DE ÓRDENES',
            'subtitle' => $metaData['subtitle'] ?? '',
            'export_date' => Carbon::now()->format('d/m/Y H:i:s'),
            'date_range' => $this->getDateRange($orders),
            'status_filter' => $this->getStatusFilter($orders)
        ];

        // Store header info for use in views
        $data['header_info'] = $headerInfo;
        
        // Use custom field mapper if provided, otherwise use default headers
        if ($this->fieldMapper) {
            $data['headers'] = array_keys($this->fieldMapper);
            $data['field_paths'] = array_values($this->fieldMapper);
        } else {
            // Default headers
            $data['headers'] = [
                'Order ID',
                'Order Number',
                'UUID',
                'Customer Email',
                'Customer Phone',
                'Status',
                'Fulfillment Status',
                'Total Gross Amount',
                'Total Net Amount',
                'Currency',
                'Reference',
                'Order Type',
                'Created At',
                'Updated At'
            ];
            $data['field_paths'] = [
                'id',
                'order_number',
                'uuid',
                'user_email',
                'user_phone',
                'status',
                'fulfillment_status',
                'total_gross_amount',
                'total_net_amount',
                'currency',
                'reference',
                'orderType.name',
                'created_at',
                'updated_at'
            ];
        }

        // Add order data using dynamic field mapping
        $data['orders'] = [];
        foreach ($orders as $order) {
            $row = [];
            foreach ($data['field_paths'] as $fieldPath) {
                $value = $this->getNestedValue($order, $fieldPath);
                $row[] = $value;
            }
            $data['orders'][] = $row;
        }

        return $data;
    }

    private function getDateRange($orders): string
    {
        // Try to extract date range from where conditions first
        if ($this->whereConditions) {
            $dateRange = $this->extractDateRangeFromWhere($this->whereConditions);
            if ($dateRange) {
                return $dateRange;
            }
        }
        
        // Fallback to orders data if no date range found in where conditions
        if ($orders->isEmpty()) {
            return 'No date range available';
        }

        $minDate = $orders->min('created_at');
        $maxDate = $orders->max('created_at');
        
        return "Desde: {$minDate->format('d/m/Y')} - Hasta: {$maxDate->format('d/m/Y')}";
    }
    
    private function extractDateRangeFromWhere(array $conditions): ?string
    {
        // Check main condition
        if (isset($conditions['column'], $conditions['operator'], $conditions['value'])) {
            $column = strtolower($conditions['column']);
            $operator = strtoupper($conditions['operator']);
            
            if ($column === 'created_at' && $operator === 'BETWEEN' && is_array($conditions['value']) && count($conditions['value']) >= 2) {
                $startDate = Carbon::parse($conditions['value'][0]);
                $endDate = Carbon::parse($conditions['value'][1]);
                return "Desde: {$startDate->format('d/m/Y')} - Hasta: {$endDate->format('d/m/Y')}";
            }
        }
        
        // Check AND conditions
        if (isset($conditions['AND']) && is_array($conditions['AND'])) {
            foreach ($conditions['AND'] as $andCondition) {
                if (is_array($andCondition) && isset($andCondition['column'], $andCondition['operator'], $andCondition['value'])) {
                    $column = strtolower($andCondition['column']);
                    $operator = strtoupper($andCondition['operator']);
                    
                    if ($column === 'created_at' && $operator === 'BETWEEN' && is_array($andCondition['value']) && count($andCondition['value']) >= 2) {
                        $startDate = Carbon::parse($andCondition['value'][0]);
                        $endDate = Carbon::parse($andCondition['value'][1]);
                        return "Desde: {$startDate->format('d/m/Y')} - Hasta: {$endDate->format('d/m/Y')}";
                    }
                }
            }
        }
        
        return null;
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
            return $value->format('Y-m-d H:i:s');
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
            return $value->format('Y-m-d H:i:s');
        }
        
        return $value;
    }

    private function getStatusFilter($orders): string
    {
        if ($orders->isEmpty()) {
            return 'No status available';
        }

        $statuses = $orders->pluck('orderStatus.name')->unique()->implode(', ');
        return "Estados: {$statuses}";
    }
}