<?php

declare(strict_types=1);

namespace Baka\Validations;

use Exception;

class Pdf
{
    /**
     * Validate if S3 file is a valid PDF
     */
    public static function isValidFile(string $fileUrl): bool
    {
        try {
            // Check file signature (magic bytes) - more reliable than just EOF
            $context = stream_context_create([
                'http' => [
                    'method' => 'GET',
                    'header' => "Range: bytes=0-4\r\n", // Only get first 5 bytes
                    'timeout' => 10,
                ],
            ]);

            $header = file_get_contents($fileUrl, false, $context);

            if ($header === false || strlen($header) < 5) {
                return false;
            }

            // Check PDF signature
            if (substr($header, 0, 4) !== '%PDF') {
                return false;
            }

            // Optional: Also check EOF (your original approach)
            return self::checkPdfEof($fileUrl);
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Check PDF EOF marker
     */
    private static function checkPdfEof(string $fileUrl): bool
    {
        try {
            $context = stream_context_create([
                'http' => [
                    'method' => 'GET',
                    'header' => "Range: bytes=-1024\r\n", // Get last 1KB instead of whole file
                    'timeout' => 10,
                ],
            ]);

            $tail = file_get_contents($fileUrl, false, $context);

            if ($tail === false) {
                return false;
            }

            return strpos($tail, '%%EOF') !== false;
        } catch (Exception $e) {
            return false;
        }
    }
}
