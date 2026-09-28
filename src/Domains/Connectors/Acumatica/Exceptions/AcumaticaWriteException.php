<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Acumatica\Exceptions;

use GuzzleHttp\Exception\RequestException;
use RuntimeException;
use Throwable;

/** Raised when a write-back to Acumatica fails or is not permitted; surfaces nested per-field errors (e.g. PostPeriod) over the generic top-level validation message. */
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
