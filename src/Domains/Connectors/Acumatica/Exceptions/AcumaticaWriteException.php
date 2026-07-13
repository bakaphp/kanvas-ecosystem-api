<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Acumatica\Exceptions;

use GuzzleHttp\Exception\RequestException;
use RuntimeException;
use Throwable;

/**
 * Raised when a write-back to Acumatica fails or is not permitted. On transport failures it surfaces
 * Acumatica's own error body (`exceptionMessage` / `message`) rather than a bare HTTP status, so the
 * operator sees why the ERP rejected the document.
 */
class AcumaticaWriteException extends RuntimeException
{
    public static function fromThrowable(Throwable $e, string $context): self
    {
        $detail = $e instanceof RequestException ? self::extractResponseMessage($e) : null;
        $message = $detail ?? $e->getMessage();

        return new self("Acumatica write failed ({$context}): {$message}", 0, $e);
    }

    private static function extractResponseMessage(RequestException $e): ?string
    {
        $response = $e->getResponse();

        if ($response === null) {
            return null;
        }

        $decoded = json_decode((string) $response->getBody(), true);

        if (! is_array($decoded)) {
            return null;
        }

        $message = $decoded['exceptionMessage'] ?? $decoded['message'] ?? null;

        return is_string($message) && $message !== '' ? $message : null;
    }
}
