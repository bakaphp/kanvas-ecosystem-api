<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Exporters\Traits;

/**
 * Small helpers for reading loosely-typed filter values the LLM passes to an exporter.
 */
trait ReadsExportFilters
{
    /**
     * @param array<string, mixed> $filters
     */
    protected function filterString(array $filters, string $key): ?string
    {
        $value = $filters[$key] ?? null;
        if ($value === null) {
            return null;
        }
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /**
     * @param array<string, mixed> $filters
     */
    protected function filterInt(array $filters, string $key): ?int
    {
        $value = $filters[$key] ?? null;

        return $value === null || $value === '' ? null : (int) $value;
    }

    /**
     * @param array<string, mixed> $filters
     */
    protected function filterBool(array $filters, string $key, bool $default): bool
    {
        return array_key_exists($key, $filters) ? (bool) $filters[$key] : $default;
    }
}
