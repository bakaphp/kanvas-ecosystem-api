<?php

declare(strict_types=1);

namespace Baka\Traits;

use Illuminate\Support\Carbon;
use JsonException;

/**
 * Defensive scalar / json coercion helpers for mapping `mixed` payloads from
 * external sources (sqlite3 -json output, jsonl, third-party APIs) into
 * typed primitives. Returns null on missing / unusable input rather than
 * throwing — caller decides whether absence is fatal.
 */
trait ScalarCoercionTrait
{
    protected function stringOrNull(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $coerced = is_string($value) ? $value : (string) $value;

        return $coerced === '' ? null : $coerced;
    }

    protected function intOrNull(mixed $value): ?int
    {
        return $value === null || $value === '' ? null : (int) $value;
    }

    protected function floatOrNull(mixed $value): ?float
    {
        return $value === null || $value === '' ? null : (float) $value;
    }

    /**
     * Numeric Unix epoch (int or float seconds, sub-second via fractional
     * component) → Carbon. Returns null for missing / non-numeric inputs.
     */
    protected function epochOrNull(mixed $value): ?Carbon
    {
        if ($value === null || $value === '' || ! is_numeric($value)) {
            return null;
        }
        $epoch = (float) $value;

        return Carbon::createFromTimestampMs((int) round($epoch * 1000.0));
    }

    /**
     * ISO-8601 string → Carbon. Distinct from epochOrNull which takes
     * numeric epochs; this is for ISO strings (e.g. JSONL session indexes).
     */
    protected function parseIso(mixed $value): ?Carbon
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Decode a JSON value into a list. Already-decoded arrays pass through
     * (reindexed); strings are json_decoded; null/empty/malformed → null.
     *
     * @return list<mixed>|null
     */
    protected function decodeJsonArray(mixed $value): ?array
    {
        if ($value === null || $value === '' || $value === '[]') {
            return null;
        }
        if (! is_string($value)) {
            return is_array($value) ? array_values($value) : null;
        }

        try {
            $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }
        if (! is_array($decoded) || $decoded === []) {
            return null;
        }

        return array_values($decoded);
    }

    /**
     * Same as decodeJsonArray but preserves object keys (for JSON objects,
     * not arrays).
     *
     * @return array<array-key, mixed>|null
     */
    protected function decodeJsonAssoc(mixed $value): ?array
    {
        if ($value === null || $value === '' || $value === '{}') {
            return null;
        }
        if (! is_string($value)) {
            return is_array($value) ? $value : null;
        }

        try {
            $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        return is_array($decoded) && $decoded !== [] ? $decoded : null;
    }
}
