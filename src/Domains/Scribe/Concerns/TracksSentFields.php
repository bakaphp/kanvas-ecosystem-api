<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Concerns;

/**
 * For an update DTO that lays the client's changes over the document's current values: it remembers which fields
 * were sent in `changed`, so the action writes those and never the rest, a snapshot that may be older than the row.
 */
trait TracksSentFields
{
    public function changes(string $field): bool
    {
        return in_array($field, $this->changed, true);
    }

    /**
     * @template TValue
     *
     * @param array<string, TValue> $values keyed by input field
     *
     * @return array<string, TValue> only the fields that were sent
     */
    public function sent(array $values): array
    {
        return array_intersect_key($values, array_flip($this->changed));
    }

    /**
     * A null only counts as sent for a field a null clears.
     *
     * @param array<string, mixed> $changes
     * @param list<string> $clearable
     *
     * @return list<string>
     */
    private static function sentFields(array $changes, array $clearable): array
    {
        return array_keys(array_filter(
            $changes,
            static fn (mixed $value, string $field): bool => $value !== null || in_array($field, $clearable, true),
            ARRAY_FILTER_USE_BOTH
        ));
    }
}
