<?php

declare(strict_types=1);

namespace Kanvas\Connectors\UniversalSeguros\Concerns;

/**
 * "Campo no obligatorio" in Universal's doc means omit the key, not send null:
 * their deserialiser turns a clean 400 into a bare 500 on an explicit null.
 * Empty arrays stay — `aditamentos: []` means "none", not "unspecified".
 */
trait OmitsNulls
{
    /**
     * @param array<array-key, mixed> $data
     *
     * @return array<array-key, mixed>
     */
    protected static function withoutNulls(array $data): array
    {
        $clean = [];

        foreach ($data as $key => $value) {
            if ($value === null) {
                continue;
            }

            $clean[$key] = is_array($value) ? self::withoutNulls($value) : $value;
        }

        return $clean;
    }
}
