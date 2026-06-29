<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Products\Actions;

use Baka\Contracts\AppInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Kanvas\Filesystem\Services\FilesystemServices;
use Kanvas\Inventory\Products\Exports\ProductExportExcel;
use Kanvas\Users\Models\Users;
use Maatwebsite\Excel\Facades\Excel;

class ExportDynamicProductsAction
{
    public function __construct(
        protected AppInterface $app,
        protected Users $user,
        protected Builder $productQuery,
        protected ?array $fieldMapper = null,
        protected ?array $metadata = null,
        protected ?array $params = null,
        protected ?string $timezone = null,
        protected ?string $filename = null
    ) {
    }

    public function execute(string $format): array
    {
        $timestamp = Carbon::now()->format('Y-m-d');
        $filename = ($this->filename ?? 'products_export') . "_{$timestamp}";

        $metaData = [
            'title' => $this->metadata['custom_title'] ?? 'REPORTE DE PRODUCTOS',
            'subtitle' => $this->metadata['subtitle'] ?? '',
            'headerImages' => $this->metadata['headerImages'] ?? [],
        ];

        if ($format === 'EXCEL') {
            return $this->toExcel($filename, $metaData);
        }

        return [
            'status' => 'error',
            'download_url' => null,
            'file_name' => null,
            'message' => 'Invalid export format specified',
        ];
    }

    private function toExcel(string $filename, array $metaData): array
    {
        $data = $this->prepareData($metaData);

        $query = $this->productQuery->with(['attributes', 'variants', 'company']);

        $export = new ProductExportExcel($data, $query, $this->timezone);

        $tempFilePath = "exports/{$filename}.xlsx";
        Excel::store($export, $tempFilePath, 'public');

        $fullTempPath = Storage::disk('public')->path($tempFilePath);

        $uploadedFile = new UploadedFile(
            $fullTempPath,
            "{$filename}.xlsx",
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            null,
            true
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

    private function prepareData(array $metaData): array
    {
        $data = [];

        $headerInfo = [
            'logos' => $metaData['headerImages'] ?? [],
            'title' => $metaData['title'] ?? 'REPORTE DE PRODUCTOS',
            'subtitle' => $metaData['subtitle'] ?? '',
            'export_date' => Carbon::now()->format('d/m/Y H:i:s'),
            'date_range' => $this->getDateRange(),
            'summary_line' => 'Total: ' . (clone $this->productQuery)->count() . ' productos',
        ];

        $data['header_info'] = $headerInfo;

        if ($this->fieldMapper) {
            $data['headers'] = array_keys($this->fieldMapper);
            $data['field_paths'] = array_values($this->fieldMapper);
        } else {
            $data['headers'] = [
                'ID',
                'UUID',
                'Name',
                'Description',
                'Is Published',
                'Created At',
                'Updated At',
            ];
            $data['field_paths'] = [
                'id',
                'uuid',
                'name',
                'description',
                'is_published',
                'created_at',
                'updated_at',
            ];
        }

        $data['orders'] = [];

        return $data;
    }

    private function getDateRange(): string
    {
        $dateField = $this->metadata['dateField'] ?? 'created_at';

        if ($this->params) {
            $range = $this->extractDateRangeFromWhere($this->params, $dateField);
            if ($range) {
                return $range;
            }
        }

        if ((clone $this->productQuery)->count() === 0) {
            return 'No date range available';
        }

        $minDate = (clone $this->productQuery)->min($dateField);
        $maxDate = (clone $this->productQuery)->max($dateField);

        return "Desde: {$this->formatDate($minDate)} - Hasta: {$this->formatDate($maxDate)}";
    }

    private function formatDate(mixed $value): string
    {
        if ($value instanceof Carbon) {
            return $value->format('Y-m-d H:i:s');
        }

        return (string) ($value ?? '');
    }

    private function extractDateRangeFromWhere(array $conditions, string $dateField): ?string
    {
        $target = strtolower($dateField);

        $check = function (array $condition) use ($target): ?string {
            $column = strtolower($condition['column'] ?? '');
            $operator = strtoupper($condition['operator'] ?? '');
            $value = $condition['value'] ?? null;

            if ($column === $target && $operator === 'BETWEEN' && is_array($value) && count($value) >= 2) {
                $start = Carbon::parse($value[0]);
                $end = Carbon::parse($value[1]);

                return "Desde: {$start->format('d/m/Y')} - Hasta: {$end->format('d/m/Y')}";
            }

            return null;
        };

        if (isset($conditions['column'], $conditions['operator'], $conditions['value'])) {
            $result = $check($conditions);
            if ($result) {
                return $result;
            }
        }

        foreach ($conditions['AND'] ?? [] as $andCondition) {
            if (is_array($andCondition)) {
                $result = $check($andCondition);
                if ($result) {
                    return $result;
                }
            }
        }

        return null;
    }
}
