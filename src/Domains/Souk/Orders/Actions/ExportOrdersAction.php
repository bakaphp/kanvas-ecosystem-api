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
        protected ?array $metadata = null
    ) {
    }

    public function execute(string $format): array
    {
       $timestamp = Carbon::now()->format('Y-m-d_H-i-s');
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
        
        $export = new class($data) implements \Maatwebsite\Excel\Concerns\FromArray {
            private $data;
            
            public function __construct($data) {
                $this->data = $data;
            }
            
            public function array(): array {
                $excelData = [];
                
                // Add header information
                $excelData[] = [$this->data['header_info']['title']];
                $excelData[] = [''];
                if (!empty($this->data['header_info']['subtitle'])) {
                    $excelData[] = [$this->data['header_info']['subtitle']];
                }
                $excelData[] = ['Fecha de exportación:', $this->data['header_info']['export_date']];
                $excelData[] = ['Rango de fechas:', $this->data['header_info']['date_range']];
                $excelData[] = ['Estados seleccionados:', $this->data['header_info']['status_filter']];
                $excelData[] = [''];
                
                // Add column headers
                $excelData[] = $this->data['headers'];
                
                // Add order data
                foreach ($this->data['orders'] as $order) {
                    $excelData[] = $order;
                }
                
                return $excelData;
            }
        };

        $filePath = "exports/{$filename}.xlsx";
        Excel::store($export, $filePath, 'public');
        
        return [
            'status' => 'success',
            'download_url' => Storage::disk('public')->url($filePath),
            'file_name' => "{$filename}.xlsx",
            'message' => 'Excel export completed successfully'
        ];
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
        if ($orders->isEmpty()) {
            return 'No date range available';
        }

        $minDate = $orders->min('created_at');
        $maxDate = $orders->max('created_at');
        
        return "Desde: {$minDate->format('d/m/Y')} - Hasta: {$maxDate->format('d/m/Y')}";
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