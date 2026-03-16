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
use Kanvas\Souk\Orders\Exports\OrderTransitionHistoryExportExcel;
use Kanvas\Users\Models\Users;
use Maatwebsite\Excel\Facades\Excel;

class ExportOrderTransitionHistoryAction
{
    public function __construct(
        protected AppInterface $app,
        protected Users $user,
        protected Collection|Builder $data,
        protected ?array $fieldMapper = null,
        protected ?array $metadata = null,
        protected ?array $params = null,
        protected ?string $timezone = null,
    ) {
    }

    public function execute(string $format): array
    {
        $timestamp = Carbon::now()->format('Y-m-d');
        $filename = "order_transition_history_export_{$timestamp}";

        $metaData = [
            'title' => $this->metadata['custom_title'] ?? 'REPORTE DE HISTORIAL DE TRANSICIONES',
            'subtitle' => $this->metadata['subtitle'] ?? '',
            'headerImages' => $this->metadata['headerImages'] ?? [],
        ];

        if ($format === 'EXCEL') {
            return $this->toExcel($this->data, $filename, $metaData);
        }

        return [
            'status' => 'error',
            'download_url' => null,
            'file_name' => null,
            'message' => 'Invalid export format specified',
        ];
    }

    private function toExcel(Collection|Builder $data, string $filename, array $metaData = []): array
    {
        $preparedData = $this->prepareData($data, $metaData);

        $query = $data instanceof Builder ? $data->with([
            'order',
            'order.orderType',
            'order.orderStatus',
            'order.allItems',
            'order.allItems.variant',
            'order.payments',
            'fromStatus',
            'toStatus',
            'changedBy',
            'endedBy',
        ]) : null;

        $export = new OrderTransitionHistoryExportExcel($preparedData, $query, $this->timezone);

        $tempFilePath = "exports/{$filename}.xlsx";
        Excel::store($export, $tempFilePath, 'public');

        $fullTempPath = Storage::disk('public')->path($tempFilePath);

        $uploadedFile = new UploadedFile(
            $fullTempPath,
            "{$filename}.xlsx",
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            null,
            true,
        );

        $filesystem = new FilesystemServices($this->app);
        $uploadedFileEntry = $filesystem->upload($uploadedFile, $this->user);

        $isSavedInFileSystem = ! empty($uploadedFileEntry->url) && $uploadedFileEntry->url !== '/';
        if ($isSavedInFileSystem) {
            if (file_exists($fullTempPath)) {
                unlink($fullTempPath);
            }
            Storage::disk('public')->delete($tempFilePath);
        }

        $this->cleanupTempImages();

        return [
            'status' => 'success',
            'download_url' => $isSavedInFileSystem ? $uploadedFileEntry->url : Storage::disk('public')->url($tempFilePath),
            'file_name' => "{$filename}.xlsx",
            'file_path' => $uploadedFileEntry->url ?? $tempFilePath,
            'message' => 'Excel export completed successfully',
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

    private function prepareData(Collection|Builder $data, array $metaData = []): array
    {
        $result = [];

        $headerInfo = [
            'logos' => $metaData['headerImages'] ?? [],
            'title' => $metaData['title'] ?? 'REPORTE DE HISTORIAL DE TRANSICIONES',
            'subtitle' => $metaData['subtitle'] ?? '',
            'export_date' => Carbon::now()->format('d/m/Y H:i:s'),
            'date_range' => $this->getDateRange($data),
            'status_filter' => $this->getStatusFilter($data),
        ];

        $result['header_info'] = $headerInfo;

        if ($this->fieldMapper) {
            $result['headers'] = array_keys($this->fieldMapper);
            $result['field_paths'] = array_values($this->fieldMapper);
        } else {
            $result['headers'] = [
                'ID',
                'Order ID',
                'Order Number',
                'From Status',
                'To Status',
                'Changed At',
                'Ended At',
                'Duration (seconds)',
                'Changed By',
                'Ended By',
                'Is Current',
                'Description',
                'Created At',
            ];
            $result['field_paths'] = [
                'id',
                'order_id',
                'order.order_number',
                'fromStatus.name',
                'toStatus.name',
                'changed_at',
                'ended_at',
                'duration_in_seconds',
                'changedBy.displayname',
                'endedBy.displayname',
                'is_current',
                'description',
                'created_at',
            ];
        }

        $result['orders'] = [];

        if ($data instanceof Collection) {
            foreach ($data as $record) {
                $row = [];
                foreach ($result['field_paths'] as $fieldPath) {
                    $row[] = $this->getNestedValue($record, $fieldPath);
                }
                $result['orders'][] = $row;
            }
        }

        return $result;
    }

    private function getDateRange(Collection|Builder $data): string
    {
        $dateField = $this->metadata['dateField'] ?? 'changed_at';

        if ($this->params) {
            $dateRange = $this->extractDateRangeFromWhere($this->params, $dateField);
            if ($dateRange) {
                return $dateRange;
            }
        }

        if ($data instanceof Builder ? $data->count() === 0 : $data->isEmpty()) {
            return 'No date range available';
        }

        $minDate = $data instanceof Builder ? $data->min($dateField) : $data->min($dateField);
        $maxDate = $data instanceof Builder ? $data->max($dateField) : $data->max($dateField);

        return "Desde: {$this->formatDate($minDate)} - Hasta: {$this->formatDate($maxDate)}";
    }

    private function extractDateRangeFromWhere(array $conditions, string $dateField = 'changed_at'): ?string
    {
        $targetDateField = strtolower($dateField);

        if (isset($conditions['column'], $conditions['operator'], $conditions['value'])) {
            $column = strtolower($conditions['column']);
            $operator = strtoupper($conditions['operator']);

            if ($column === $targetDateField && $operator === 'BETWEEN' && is_array($conditions['value']) && count($conditions['value']) >= 2) {
                $startDate = Carbon::parse($conditions['value'][0]);
                $endDate = Carbon::parse($conditions['value'][1]);

                return "Desde: {$startDate->format('d/m/Y')} - Hasta: {$endDate->format('d/m/Y')}";
            }
        }

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

    private function formatDate(mixed $value): string
    {
        if ($value instanceof Carbon) {
            return $value->format('Y-m-d H:i:s');
        }

        return (string) $value;
    }

    private function getStatusFilter(Collection|Builder $data): string
    {
        if ($data instanceof Builder) {
            $records = (clone $data)->with('toStatus')->take(1000)->get();
        } else {
            $records = $data;
        }

        if ($records->isEmpty()) {
            return 'No status available';
        }

        $statusCounts = $records->groupBy('toStatus.name')->map(function ($group) {
            return $group->count();
        });

        $formattedStatuses = $statusCounts->map(function ($count, $statusName) {
            return "{$statusName} {$count}";
        })->implode(', ');

        return "Estados: {$formattedStatuses}";
    }

    private function getNestedValue(mixed $object, string $path): mixed
    {
        if (strpos($path, '[') !== false) {
            return $this->getArrayValue($object, $path);
        }

        $keys = explode('.', $path);
        $value = $object;

        foreach ($keys as $key) {
            if (is_object($value)) {
                if (method_exists($value, 'relationLoaded') && $value->relationLoaded($key)) {
                    $value = $value->getRelation($key);
                } elseif (method_exists($value, 'getAttribute')) {
                    $value = $value->getAttribute($key);
                } elseif (property_exists($value, $key)) {
                    $value = $value->$key;
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
            return $value->format('Y-m-d H:i:s');
        }

        return $value;
    }

    private function getArrayValue(mixed $object, string $path): mixed
    {
        preg_match_all('/([^.\[]+)(\[(\d+)\])?/', $path, $matches);

        $value = $object;

        for ($i = 0; $i < count($matches[1]); $i++) {
            $key = $matches[1][$i];
            $index = $matches[3][$i] ?? null;

            if (is_object($value)) {
                if (method_exists($value, 'relationLoaded') && $value->relationLoaded($key)) {
                    $value = $value->getRelation($key);
                } elseif (method_exists($value, 'getAttribute')) {
                    $value = $value->getAttribute($key);
                } elseif (property_exists($value, $key)) {
                    $value = $value->$key;
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
                } elseif ($value instanceof Collection) {
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
            return $value->format('Y-m-d H:i:s');
        }

        return $value;
    }
}
