<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Microsoft\OneDrive\Documents\Support;

use Closure;
use Kanvas\Connectors\Microsoft\OneDrive\Enums\OneDriveErrorReasonEnum;
use Kanvas\Connectors\Microsoft\OneDrive\Exceptions\OneDriveException;

/**
 * ZipArchive and the PhpOffice readers/writers only work on paths, so document bytes take a
 * round trip through a tempnam() file that is always removed afterwards.
 */
class TemporaryFile
{
    private const string PREFIX = 'kanvas-onedrive';

    /**
     * @template T
     *
     * @param Closure(string): T $callback receives the path of a file holding $bytes
     *
     * @return T
     */
    public static function withBytes(string $bytes, Closure $callback): mixed
    {
        $path = tempnam(sys_get_temp_dir(), self::PREFIX);
        if ($path === false) {
            throw new OneDriveException(OneDriveErrorReasonEnum::REQUEST_FAILED, 'Could not create a temporary file.');
        }

        try {
            if (file_put_contents($path, $bytes) === false) {
                throw new OneDriveException(OneDriveErrorReasonEnum::REQUEST_FAILED, 'Could not write a temporary file.');
            }

            return $callback($path);
        } finally {
            @unlink($path);
        }
    }

    public static function contents(string $path): string
    {
        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new OneDriveException(OneDriveErrorReasonEnum::REQUEST_FAILED, 'Could not read a temporary file.');
        }

        return $contents;
    }
}
