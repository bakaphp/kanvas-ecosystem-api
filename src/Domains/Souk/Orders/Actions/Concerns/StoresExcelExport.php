<?php

declare(strict_types=1);

namespace Kanvas\Souk\Orders\Actions\Concerns;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Kanvas\Apps\Models\Apps;
use Kanvas\Filesystem\Services\FilesystemServices;
use Kanvas\Users\Models\Users;
use Maatwebsite\Excel\Facades\Excel;

trait StoresExcelExport
{
    protected function storeExcelExport(
        object $export,
        string $filename,
        Apps $app,
        Users $user
    ): array {
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

        $uploadedFileEntry = new FilesystemServices($app)->upload($uploadedFile, $user);

        $isSavedInFileSystem = ! empty($uploadedFileEntry->url) && $uploadedFileEntry->url !== '/';
        if ($isSavedInFileSystem && file_exists($fullTempPath)) {
            unlink($fullTempPath);
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
}
