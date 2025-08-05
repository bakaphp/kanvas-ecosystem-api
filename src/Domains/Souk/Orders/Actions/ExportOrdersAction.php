<?php

declare(strict_types=1);

namespace Kanvas\Souk\Orders\Actions;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Kanvas\Souk\Orders\Exports\OrderExport;
use Kanvas\Souk\Orders\Jobs\GeneratePdfJob;
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
        } elseif ($format === 'PDF') {
            return $this->toPdf($this->orderData, $filename, $metaData);
        }

        return [
            'status' => 'error',
            'download_url' => null,
            'file_name' => null,
            'message' => 'Invalid export format specified'
        ];
    }

    protected function generatePdfHtml(array $data, array $headers, array $logos = []): string
    {
        $logoHtml = '';
        if (! empty($logos)) {
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

    private function toExcel($orders, string $filename, array $metaData = []): array
    {
        $data = $this->prepareOrderData($orders, $metaData);

        $export = new OrderExport($data);

        $filePath = "exports/{$filename}.xlsx";
        Excel::store($export, $filePath, 'public');

        // Clean up temporary image files
        $this->cleanupTempImages();

        return [
            'status' => 'success',
            'download_url' => Storage::disk('public')->url($filePath),
            'file_name' => "{$filename}.xlsx",
            'file_path' => $filePath,
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

        // Generate HTML content
        $html = $this->generatePdfHtml(
            $data['orders'],
            $data['headers'],
            $data['header_info']['logos'] ?? []
        );

        // Use queue job for PDF generation to avoid Swoole issues
        // dispatchSync runs the job immediately and waits for the result
        $job = new GeneratePdfJob($html, $filename);
        return $job->dispatchSync();
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
