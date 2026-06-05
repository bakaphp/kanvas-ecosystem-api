<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Movipass\Actions;

use Baka\Contracts\AppInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Kanvas\Connectors\Movipass\Exports\MechanicsExport;
use Kanvas\Filesystem\Services\FilesystemServices;
use Kanvas\Users\Models\Users;
use Maatwebsite\Excel\Facades\Excel;

class ExportMechanicsAction
{
    private const DEFAULT_HEADERS = [
        'ID',
        'Nombre',
        'Email',
        'Teléfono',
        'Disponibilidad',
        'Latitud',
        'Longitud',
        'Vehículo',
        'Empresa',
        'Roles',
        'Creado',
    ];

    public function __construct(
        protected AppInterface $app,
        protected Users $user,
        protected Builder $mechanics,
        protected ?array $fieldMapper = null,
        protected ?array $metadata = null,
        protected ?string $timezone = null,
    ) {
    }

    public function execute(string $format): array
    {
        if ($format !== 'EXCEL') {
            return [
                'status' => 'error',
                'download_url' => null,
                'file_name' => null,
                'message' => 'Invalid export format specified',
            ];
        }

        $filename = 'roadside_assistance_mechanics_' . Carbon::now()->format('Y-m-d');
        $data = $this->prepareData();

        $export = new MechanicsExport($data, $this->mechanics, $this->timezone);

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

        return [
            'status' => 'success',
            'download_url' => $isSavedInFileSystem ? $uploadedFileEntry->url : Storage::disk('public')->url($tempFilePath),
            'file_name' => "{$filename}.xlsx",
            'file_path' => $uploadedFileEntry->url ?? $tempFilePath,
            'message' => 'Excel export completed successfully',
        ];
    }

    private function prepareData(): array
    {
        if ($this->fieldMapper) {
            $headers = array_keys($this->fieldMapper);
            $fieldPaths = array_values($this->fieldMapper);
        } else {
            $headers = self::DEFAULT_HEADERS;
            $fieldPaths = [];
        }

        return [
            'header_info' => [
                'logos' => $this->metadata['headerImages'] ?? [],
                'title' => $this->metadata['custom_title'] ?? 'REPORTE DE TÉCNICOS / MECÁNICOS',
                'subtitle' => $this->metadata['subtitle'] ?? '',
                'export_date' => Carbon::now()->format('d/m/Y H:i:s'),
                'date_range' => '',
                'status_filter' => '',
            ],
            'headers' => $headers,
            'field_paths' => $fieldPaths,
            'orders' => [],
        ];
    }
}
