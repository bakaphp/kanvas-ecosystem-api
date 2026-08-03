<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Acumatica\Exceptions;

use GuzzleHttp\Exception\RequestException;
use RuntimeException;
use Throwable;

/**
 * Raised when a write-back to Acumatica fails or is not permitted. On transport failures it surfaces
 * Acumatica's own error body rather than a bare HTTP status, so the operator sees why the ERP
 * rejected the document.
 *
 * A PUT validation failure on an entity puts its top-level `error` at "raised at least one error,
 * please review the errors" — the actual cause sits nested per-field, e.g.
 * `"PostPeriod": {"value": "082026", "error": "Error: The 08-2026 financial period is inactive..."}`.
 * Those field-level errors (including inside `Details` line items) are what actually explain the
 * rejection, so they take priority over the generic top-level message.
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

        $fieldErrors = self::collectFieldErrors($decoded);

        if ($fieldErrors !== []) {
            return implode('; ', $fieldErrors);
        }

        $message = $decoded['exceptionMessage'] ?? $decoded['message'] ?? $decoded['error'] ?? null;

        if (is_string($message) && $message !== '') {
            return $message;
        }

        // Per-row validation errors nest their own 'error' field, e.g. {"id":..., "error": "..."}.
        if (isset($decoded[0]['error']) && is_string($decoded[0]['error']) && $decoded[0]['error'] !== '') {
            return $decoded[0]['error'];
        }

        return null;
    }

    /**
     * Walks a decoded entity record (and its `Details` line items, one level deep) for
     * `{"value": ..., "error": "..."}`-shaped fields — the specific validation failure(s) behind a
     * generic top-level "raised at least one error" message.
     *
     * @param array<string, mixed> $record
     *
     * @return array<int, string>
     */
    private static function collectFieldErrors(array $record): array
    {
        $errors = [];

        foreach ($record as $field => $value) {
            if ($field === 'Details' && is_array($value)) {
                foreach ($value as $line) {
                    if (is_array($line)) {
                        $errors = [...$errors, ...self::collectFieldErrors($line)];
                    }
                }

                continue;
            }

            if (is_array($value) && isset($value['error']) && is_string($value['error']) && $value['error'] !== '') {
                $errors[] = "{$field}: {$value['error']}";
            }
        }

        return $errors;
    }
}
