<?php

declare(strict_types=1);

namespace Kanvas\Souk\Orders\Actions;

use Baka\Contracts\AppInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Kanvas\Filesystem\Services\FilesystemServices;
use Kanvas\Souk\Orders\Exports\OrderExportExcel;
use Kanvas\Souk\Orders\Jobs\GeneratePdfJob;
use Kanvas\Users\Models\Users;
use Maatwebsite\Excel\Facades\Excel;

class ExportOrdersAction
{
    public function __construct(
        protected AppInterface $app,
        protected Users $user,
        protected Collection|Builder $orderData,
        protected ?array $fieldMapper = null,
        protected ?array $metadata = null,
        protected ?array $params = null,
        protected ?string $timezone = null
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


    private function toExcel($orders, string $filename, array $metaData = []): array
    {
        $data = $this->prepareOrderData($orders, $metaData);

        // If orders is a Builder, pass it as the query; otherwise use null for collection
        $query = $orders instanceof Builder ? $orders->with([
            'user',
            'company',
            'orderType',
            'orderStatus',
            'allItems',
            'allItems.variant',
        ]) : null;

        $export = new OrderExportExcel($data, $query, $this->timezone);

        $tempFilePath = "exports/{$filename}.xlsx";
        Excel::store($export, $tempFilePath, 'public');

        // Get the full path to the temporary file
        $fullTempPath = Storage::disk('public')->path($tempFilePath);

        // Create an UploadedFile instance from the temporary file
        $uploadedFile = new UploadedFile(
            $fullTempPath,
            "{$filename}.xlsx",
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            null,
            true
        );

        // Upload to FileSystem
        $filesystem = new FilesystemServices($this->app);
        $uploadedFileEntry = $filesystem->upload($uploadedFile, $this->user);

        // Clean up the temporary file if upload was successful and URL is not empty or '/'
        $isSavedInFileSystem = ! empty($uploadedFileEntry->url) && $uploadedFileEntry->url !== '/';
        if ($isSavedInFileSystem) {
            if (file_exists($fullTempPath)) {
                unlink($fullTempPath);
            }
            // Also clean up from storage disk
            Storage::disk('public')->delete($tempFilePath);
        }

        // Clean up temporary image files
        $this->cleanupTempImages();

        return [
            'status' => 'success',
            'download_url' => $isSavedInFileSystem ? $uploadedFileEntry->url : Storage::disk('public')->url($tempFilePath),
            'file_name' => "{$filename}.xlsx",
            'file_path' => $uploadedFileEntry->url ?? $tempFilePath,
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
        $order = $this->orderData->first();

        GeneratePdfJob::dispatch(
            $order,
            $this->user,
            'orders-pdf',
            $filename,
            [
                "orders" => $data['orders'],
                "headers" => $data['headers'],
                "logos" => $data['header_info']['logos'] ?? []
            ]
        );

        return [
            'status' => 'processing',
            'download_url' => null,
            'file_name' => "{$filename}.pdf",
            'file_path' => null,
            'message' => 'PDF generation started. You will receive an email with the download link when ready.',
        ];
    }



    private function prepareOrderData($orders, array $metaData = []): array
    {
        $data = [];

        // For Builder objects, execute query once for metadata
        $ordersForMetadata = $orders instanceof Builder ?
            $orders->with('orderStatus')->take(1000)->get() :
            $orders;

        // Header information with logos and company details
        $headerInfo = [
            'logos' => $metaData['headerImages'] ?? [],
            'title' => $metaData['title'] ?? 'REPORTE DE ÓRDENES',
            'subtitle' => $metaData['subtitle'] ?? '',
            'export_date' => Carbon::now()->format('d/m/Y H:i:s'),
            'date_range' => $this->getDateRange($orders),
            'status_filter' => $this->getStatusFilter($ordersForMetadata)
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

        // For FromQuery approach, we don't need to process orders here
        // The data will be processed in the map() method of OrderExportExcel
        $data['orders'] = [];

        // If it's a collection (legacy), process it
        if ($orders instanceof Collection) {
            foreach ($orders as $order) {
                $row = [];
                foreach ($data['field_paths'] as $fieldPath) {
                    $value = $this->getNestedValue($order, $fieldPath);
                    $row[] = $value;
                }
                $data['orders'][] = $row;
            }
        }

        return $data;
    }

    private function formatDate($value): String
    {
        if ($value instanceof \Carbon\Carbon) {
            return $value->format('Y-m-d H:i:s');
        }

        return $value;
    }

    private function getDateRange($orders): string
    {
        // Get the date field to use (from metadata or default to created_at)
        $dateField = $this->metadata['dateField'] ?? 'created_at';
        // Try to extract date range from where conditions first
        if ($this->params) {
            $dateRange = $this->extractDateRangeFromWhere($this->params, $dateField);
            if ($dateRange) {
                return $dateRange;
            }
        }

        // Fallback to orders data if no date range found in where conditions
        if ($orders instanceof Builder ? $orders->count() === 0 : $orders->isEmpty()) {
            return 'No date range available';
        }

        if ($orders instanceof Builder) {
            $minDate = $orders->min($dateField);
            $maxDate = $orders->max($dateField);
        } else {
            $minDate = $orders->min($dateField);
            $maxDate = $orders->max($dateField);
        }

        return "Desde: {$this->formatDate($minDate)} - Hasta: {$this->formatDate($maxDate)}";
    }

    private function extractDateRangeFromWhere(array $conditions, string $dateField = 'created_at'): ?string
    {
        $targetDateField = strtolower($dateField);
        // Check main condition
        if (isset($conditions['column'], $conditions['operator'], $conditions['value'])) {
            $column = strtolower($conditions['column']);
            $operator = strtoupper($conditions['operator']);

            if ($column === $targetDateField && $operator === 'BETWEEN' && is_array($conditions['value']) && count($conditions['value']) >= 2) {
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

                    if ($column === $targetDateField && $operator === 'BETWEEN' && is_array($andCondition['value']) && count($andCondition['value']) >= 2) {
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
        if ($orders instanceof Collection ? $orders->isEmpty() : $orders->count() === 0) {
            return 'No status available';
        }

        // Now $orders is always a Collection (from prepareOrderData)
        $statuses = $orders->pluck('orderStatus.name')->unique()->implode(', ');

        return "Estados: {$statuses}";
    }
}
