<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Acumatica\Support;

/**
 * Acumatica's contract-based REST wraps every scalar field as `{"value": x}` on the way in and
 * back out (nested `Details[]` line arrays follow the same rule per element). These helpers convert
 * between a flat PHP map and that wire shape so write actions don't hand-wrap every field.
 */
final class AcumaticaPayload
{
    /**
     * Wrap a flat field map into Acumatica's `{value:}` form. Null values are dropped (Acumatica
     * treats an absent field as "leave unchanged"); pass an explicit empty string to clear one.
     *
     * @param array<string, mixed> $fields
     *
     * @return array<string, array{value: mixed}>
     */
    public static function wrap(array $fields): array
    {
        $wrapped = [];

        foreach ($fields as $key => $value) {
            if ($value === null) {
                continue;
            }

            $wrapped[$key] = ['value' => $value];
        }

        return $wrapped;
    }

    /**
     * Read a wrapped field's value back out of an Acumatica record.
     *
     * @param array<array-key, mixed> $record
     */
    public static function value(array $record, string $field, mixed $default = null): mixed
    {
        return data_get($record, "{$field}.value", $default);
    }

    /**
     * Escape a value for an OData `$filter` string literal (single quotes are doubled).
     */
    public static function escapeLiteral(string $value): string
    {
        return str_replace("'", "''", $value);
    }

    /**
     * The record's Acumatica primary key (the `id` GUID contract-based REST returns on create).
     *
     * @param array<array-key, mixed> $record
     */
    public static function recordId(array $record): ?string
    {
        $id = $record['id'] ?? null;

        return is_string($id) && $id !== '' ? $id : null;
    }

    /**
     * The `_links.files:put` href — a `{filename}` template you PUT raw bytes to, to attach a file.
     * Acumatica serializes `_links` entries as either a bare href string or an `{href}` object, so
     * handle both.
     *
     * @param array<array-key, mixed> $record
     */
    public static function filesPutHref(array $record): ?string
    {
        $link = data_get($record, '_links.files:put');

        if (is_array($link)) {
            $link = $link['href'] ?? null;
        }

        return is_string($link) && $link !== '' ? $link : null;
    }
}
