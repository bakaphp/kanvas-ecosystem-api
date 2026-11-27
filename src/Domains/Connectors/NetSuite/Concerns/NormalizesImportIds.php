<?php

declare(strict_types=1);

namespace Kanvas\Connectors\NetSuite\Concerns;

use Baka\Support\Str;

trait NormalizesImportIds
{
    /**
     * A saved search joined to locations repeats a record once per location, hence the unique.
     *
     * @param array<mixed> $ids
     * @return list<string>
     */
    protected function uniqueIds(array $ids): array
    {
        return array_values(array_unique(array_filter(
            array_map(fn (mixed $id) => Str::trimToNull((string) $id), $ids),
            fn (?string $id) => $id !== null
        )));
    }
}
