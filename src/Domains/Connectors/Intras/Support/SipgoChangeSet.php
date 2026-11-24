<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Intras\Support;

use Baka\Support\Str;
use stdClass;

/**
 * Only what differs between what Kanvas wants SIPGO to hold and what it holds, as from → to. Empty
 * when SIPGO already matches. Catalog columns compare by name first: a value SIPGO already holds
 * under the same name is not a change and is never resolved, so a duplicate catalog name only
 * refuses when the value actually moved.
 */
class SipgoChangeSet
{
    /**
     * @param array{row?: array<string, string>, custom_fields?: array<string, string>, catalog?: array<string, array{table: string, name: string}>} $desired
     * @param array<string, string> $current custom field name => value
     *
     * @return array{row?: array<string, array>, custom_fields?: array<string, array{from: ?string, to: string}>}
     */
    public static function build(
        array $desired,
        stdClass $row,
        array $current,
        SipgoCatalog $catalog
    ): array {
        $changes = ['row' => [], 'custom_fields' => []];

        foreach ($desired['row'] ?? [] as $column => $value) {
            $from = Str::trimToNull((string) ($row->{$column} ?? ''));
            if ($from !== $value) {
                $changes['row'][$column] = ['from' => $from, 'to' => $value];
            }
        }

        foreach ($desired['catalog'] ?? [] as $column => $spec) {
            $currentId = isset($row->{$column}) ? (int) $row->{$column} : null;
            $currentName = $catalog->nameFor($spec['table'], $currentId);

            if ($currentName !== null && SipgoCatalog::normalize($currentName) === SipgoCatalog::normalize($spec['name'])) {
                continue;
            }

            $id = $catalog->idFor($spec['table'], $spec['name']);

            $changes['row'][$column] = [
                'from' => $currentId === null ? null : (string) $currentId,
                'to' => (string) $id,
                'label' => ['from' => $currentName, 'to' => $catalog->nameFor($spec['table'], $id)],
            ];
        }

        foreach ($desired['custom_fields'] ?? [] as $name => $value) {
            $from = Str::trimToNull($current[$name] ?? null);
            if ($from !== $value) {
                $changes['custom_fields'][$name] = ['from' => $from, 'to' => $value];
            }
        }

        return array_filter($changes);
    }
}
