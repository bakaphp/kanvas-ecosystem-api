<?php

declare(strict_types=1);

namespace Kanvas\Connectors\ESim\Support;

use InvalidArgumentException;

class FileSizeConverter
{
    /**
    * Convert a human-readable file size string (e.g., "500MB", "2GB", "500 MB", "2 GB", "0") to bytes.
    *
    * @throws InvalidArgumentException
    */
    public static function toBytes(string $size): int
    {
        $size = strtoupper(trim($size));

        // Remove spaces between number and unit (e.g., "500 MB" -> "500MB")
        $size = preg_replace('/\s+/', '', $size);

        // If the input is "0" or "0[unit]", return 0 bytes
        if (preg_match('/^0(B|KB|MB|GB|TB)?$/i', $size)) {
            return 0;
        }

        $units = [
            'B' => 1,
            'KB' => 1000,
            'MB' => 1000000,
            'GB' => 1000000000,
            'TB' => 1000000000000,
        ];

        if (! preg_match('/^([\d.]+)(B|KB|MB|GB|TB)$/i', $size, $matches)) {
            throw new InvalidArgumentException("Invalid size format: $size");
        }

        [$fullMatch, $number, $unit] = $matches;

        return (int) ($number * $units[$unit]);
    }
}
