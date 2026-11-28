<?php

declare(strict_types=1);

namespace Kanvas\ActionEngine\Engagements\Services;

use finfo;
use Illuminate\Http\UploadedFile;
use ZipArchive;

class EngagementUploadFileValidator
{
    public const int MAX_FILE_SIZE_BYTES = 100 * 1024 * 1024;

    public function isSupported(UploadedFile $file): bool
    {
        $path = $file->getRealPath();
        if ($path === false || ! is_file($path)) {
            return false;
        }

        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($path);
        if (! is_string($mime)) {
            return false;
        }

        $extension = strtolower($file->getClientOriginalExtension());

        if ($mime === 'application/pdf') {
            return $extension === 'pdf' && str_starts_with($this->readHeader($path, 5), '%PDF-');
        }

        if (in_array($mime, [
            'application/vnd.ms-excel',
            'application/x-ole-storage',
            'application/CDFV2',
            'application/octet-stream',
        ], true)) {
            return $extension === 'xls' && str_starts_with(
                $this->readHeader($path, 8),
                "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1"
            );
        }

        if ($extension === 'xlsx') {
            return $this->isXlsx($path);
        }

        if ($extension === 'heic' && $this->hasHeicBrand($path)) {
            return true;
        }

        return match ($mime) {
            'image/jpeg' => in_array($extension, ['jpg', 'jpeg'], true),
            'image/png' => $extension === 'png',
            'image/webp' => $extension === 'webp',
            default => false,
        };
    }

    private function isXlsx(string $path): bool
    {
        if (! class_exists(ZipArchive::class)) {
            return false;
        }

        $zip = new ZipArchive();
        $opened = $zip->open($path);
        if ($opened !== true && $opened !== ZipArchive::ER_OK) {
            return false;
        }

        try {
            return $zip->locateName('[Content_Types].xml') !== false
                && $zip->locateName('xl/workbook.xml') !== false;
        } finally {
            $zip->close();
        }
    }

    private function hasHeicBrand(string $path): bool
    {
        $header = $this->readHeader($path, 12);

        return strlen($header) === 12
            && substr($header, 4, 4) === 'ftyp'
            && in_array(substr($header, 8, 4), ['heic', 'heix'], true);
    }

    private function readHeader(string $path, int $length): string
    {
        $stream = fopen($path, 'rb');
        if ($stream === false) {
            return '';
        }

        try {
            return (string) fread($stream, $length);
        } finally {
            fclose($stream);
        }
    }
}
