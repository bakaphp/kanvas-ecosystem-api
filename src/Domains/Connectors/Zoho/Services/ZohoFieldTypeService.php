<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Zoho\Services;

use Carbon\Carbon;
use Throwable;

/**
 * Zoho validates every value against the column type of its own field and rejects the whole record
 * with INVALID_DATA when it doesn't parse — a currency field will not take "$50,000", a date field
 * will not take "08/28/2026". Lead payloads are assembled from receiver form input plus a per-company
 * field map, so formatted strings reach the API constantly and cost us the entire lead.
 *
 * Zoho names the offending field and the type it wanted (api_name + expected_data_type), which is
 * enough to coerce the value and retry. Everything here is pure so it can be unit tested without the
 * API. See Sentry KANVAS-ECOSYSTEM InvalidDataType on Amount_Requested.
 */
final class ZohoFieldTypeService
{
    private const DECIMAL_TYPES = ['currency', 'double', 'decimal', 'float'];
    private const INTEGER_TYPES = ['integer', 'bigint', 'long'];

    public static function isNumericType(string $expectedType): bool
    {
        $type = strtolower(trim($expectedType));

        return in_array($type, self::DECIMAL_TYPES, true) || in_array($type, self::INTEGER_TYPES, true);
    }

    public static function canCast(string $expectedType): bool
    {
        $type = strtolower(trim($expectedType));

        return self::isNumericType($type) || in_array($type, ['boolean', 'date', 'datetime'], true);
    }

    /**
     * Null means "we can't make this value fit that type" — the caller drops the field.
     */
    public static function cast(mixed $value, string $expectedType): mixed
    {
        if ($value === null || is_array($value) || is_object($value)) {
            return null;
        }

        $type = strtolower(trim($expectedType));
        $raw = trim((string) $value);

        if (in_array($type, self::DECIMAL_TYPES, true)) {
            return self::toNumber($raw);
        }

        if (in_array($type, self::INTEGER_TYPES, true)) {
            $number = self::toNumber($raw);

            return $number === null ? null : (int) $number;
        }

        return match ($type) {
            'boolean' => filter_var($raw, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE),
            'date' => self::toDate($raw, 'Y-m-d'),
            'datetime' => self::toDate($raw, 'c'),
            default => null,
        };
    }

    /**
     * Rebuild a rejected payload with the types Zoho asked for. Returns null when nothing changed,
     * so the caller knows a retry would just be the same call again.
     */
    public static function coercePayload(array $data, ?array $body): ?array
    {
        $changed = false;

        foreach (self::invalidTypeFields($body) as $apiName => $expectedType) {
            $key = self::resolveKey($data, $apiName);

            if ($key === null) {
                continue;
            }

            $cast = self::cast($data[$key], $expectedType);

            if ($cast === $data[$key]) {
                continue;
            }

            if ($cast === null) {
                unset($data[$key]);
            } else {
                $data[$key] = $cast;
            }

            $changed = true;
        }

        return $changed ? $data : null;
    }

    /**
     * Reads the raw decoded response body (keys preserved) rather than the SDK's details(), which
     * flattens the api_name/expected_data_type pair into a positional list.
     *
     * @return array<string, string> api_name => expected_data_type
     */
    public static function invalidTypeFields(?array $body): array
    {
        $rows = $body['data'] ?? [$body];
        $fields = [];

        foreach ($rows as $row) {
            if (! is_array($row) || ($row['code'] ?? null) !== 'INVALID_DATA') {
                continue;
            }

            $apiName = $row['details']['api_name'] ?? null;

            if (! is_string($apiName) || $apiName === '') {
                continue;
            }

            $fields[$apiName] = (string) ($row['details']['expected_data_type'] ?? '');
        }

        return $fields;
    }

    private static function resolveKey(array $data, string $apiName): ?string
    {
        if (array_key_exists($apiName, $data)) {
            return $apiName;
        }

        foreach (array_keys($data) as $key) {
            if (strcasecmp((string) $key, $apiName) === 0) {
                return (string) $key;
            }
        }

        return null;
    }

    private static function toNumber(string $value): ?float
    {
        $number = preg_replace('/[^0-9.\-]/', '', $value);

        return is_numeric($number) ? (float) $number : null;
    }

    private static function toDate(string $value, string $format): ?string
    {
        try {
            return Carbon::parse($value)->format($format);
        } catch (Throwable) {
            return null;
        }
    }
}
