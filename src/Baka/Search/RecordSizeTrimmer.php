<?php

declare(strict_types=1);

namespace Baka\Search;

use Baka\Support\Arr;
use Baka\Support\Str;
use stdClass;

/**
 * Algolia rejects the whole batch when a single record is over the plan's byte cap, so an
 * oversized record has to be shrunk before it ships.
 *
 * Every step here is a no-op once the record fits, which is what lets a model describe its
 * trimming as an ordered "sacrifice this first" list instead of re-checking the size between
 * each cut. Order matters: the first step listed is the first detail lost.
 */
final class RecordSizeTrimmer
{
    private function __construct(
        private array $record,
        private readonly int $limit
    ) {
    }

    public static function make(array $record, int $limit): self
    {
        return new self($record, $limit);
    }

    public function fits(): bool
    {
        return $this->size() <= $this->limit;
    }

    public function size(): int
    {
        return Arr::sizeInBytes($this->record);
    }

    public function get(): array
    {
        return $this->record;
    }

    /**
     * Model-specific cut that doesn't generalize (rebuilding a nested collection, dropping a
     * relation only this model has).
     */
    public function trim(callable $step): self
    {
        if ($this->fits()) {
            return $this;
        }

        $this->record = $step($this->record);

        return $this;
    }

    /**
     * Shorten the long strings under $key, gently first. No key is ever lost — facets built on
     * `<bucket>.*.<field>` break when keys vanish, and short values stay untouched.
     */
    public function truncateStrings(string $key, array $lengths = [500, 200, 100]): self
    {
        foreach ($lengths as $maxLength) {
            if ($this->fits()) {
                return $this;
            }

            $value = $this->record[$key] ?? null;
            if (! is_array($value) && ! $value instanceof stdClass) {
                return $this;
            }

            $truncated = Arr::truncateStrings((array) $value, $maxLength);
            $this->record[$key] = $value instanceof stdClass ? (object) $truncated : $truncated;
        }

        return $this;
    }

    public function limitString(string $key, int ...$lengths): self
    {
        foreach ($lengths as $length) {
            if ($this->fits() || ! is_string($this->record[$key] ?? null)) {
                return $this;
            }

            $this->record[$key] = Str::limit($this->record[$key], $length, '');
        }

        return $this;
    }

    public function forget(string ...$keys): self
    {
        foreach ($keys as $key) {
            if ($this->fits()) {
                return $this;
            }

            unset($this->record[$key]);
        }

        return $this;
    }

    public function keepFirst(string $key, int $count): self
    {
        if ($this->fits() || count((array) ($this->record[$key] ?? [])) <= $count) {
            return $this;
        }

        $this->record[$key] = array_slice((array) $this->record[$key], 0, $count);

        return $this;
    }

    /**
     * Shed entries from one field, heaviest first, and stop as soon as the record fits.
     * Wiping the field wholesale would take the cheap entries down with the expensive one.
     */
    public function dropHeaviestEntries(string $key): self
    {
        if (! is_array($this->record[$key] ?? null)) {
            return $this;
        }

        $entries = $this->record[$key];
        $isList = array_is_list($entries);

        while (! empty($entries) && ! $this->fits()) {
            $heaviest = collect($entries)
                ->map(fn ($value, $entryKey) => Arr::sizeInBytes([$entryKey => $value]))
                ->sortDesc()
                ->keys()
                ->first();

            unset($entries[$heaviest]);
            $this->record[$key] = $isList ? array_values($entries) : $entries;
        }

        return $this;
    }

    /**
     * Backstop for records with no bounded shape (a body of unknown size, relations serialized in
     * by toArray()): shorten every string left anywhere in the record.
     */
    public function truncateEverything(int ...$lengths): self
    {
        foreach ($lengths as $maxLength) {
            if ($this->fits()) {
                return $this;
            }

            $this->record = Arr::truncateStrings($this->record, $maxLength);

            // Arr::truncateStrings only walks arrays; object-valued fields (Typesense declares
            // Message.message as `object`) keep their type but still shrink inside.
            foreach ($this->record as $key => $value) {
                if ($value instanceof stdClass) {
                    $this->record[$key] = (object) Arr::truncateStrings((array) $value, $maxLength);
                }
            }
        }

        return $this;
    }

    /**
     * Last resort: pop entries off the tail until the record fits. $onDropped fires only when
     * something was actually lost — silent loss of a whole collection is how a tenant ended up
     * indexed with empty variants.
     */
    public function popUntilFit(string $key, ?callable $onDropped = null): self
    {
        $dropped = 0;

        while (! empty($this->record[$key]) && ! $this->fits()) {
            array_pop($this->record[$key]);
            $dropped++;
        }

        if ($dropped > 0 && $onDropped !== null) {
            $onDropped($dropped, $this->record);
        }

        return $this;
    }
}
