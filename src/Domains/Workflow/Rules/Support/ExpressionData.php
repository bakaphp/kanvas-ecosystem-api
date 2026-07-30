<?php

declare(strict_types=1);

namespace Kanvas\Workflow\Rules\Support;

use ArrayAccess;
use Override;

/**
 * Wraps an array so Symfony ExpressionLanguage can navigate it with BOTH dot and bracket syntax.
 *
 * JSON columns (e.g. Order.metadata cast via Baka\Casts\Json) surface as plain PHP arrays in
 * $entity->toArray(). Symfony's `.` operator is a property access that only works on objects, so a
 * rule authored as `metadata.data.user_company_id` throws "Unable to get property of non-object".
 * Implementing ArrayAccess + __get lets `metadata.data.x` and `metadata['data']['x']` both resolve,
 * and returns null (instead of throwing) for a missing nested key.
 *
 * @implements ArrayAccess<array-key, mixed>
 */
final class ExpressionData implements ArrayAccess
{
    /** @param array<array-key, mixed> $data */
    public function __construct(
        private readonly array $data
    ) {
    }

    /**
     * Wrap every array entry in the ExpressionLanguage values map so top-level JSON/array attributes
     * become dot-navigable. Scalars and objects (the entity, relations, app) pass through untouched —
     * only arrays are wrapped, and only navigation (dot/bracket) semantics change, never comparison.
     *
     * @param array<array-key, mixed> $values
     *
     * @return array<array-key, mixed>
     */
    public static function wrapValues(array $values): array
    {
        return array_map(
            fn (mixed $value): mixed => is_array($value) ? new self($value) : $value,
            $values
        );
    }

    public function __get(string $name): mixed
    {
        return $this->wrap($this->data[$name] ?? null);
    }

    public function __isset(string $name): bool
    {
        return isset($this->data[$name]);
    }

    #[Override]
    public function offsetExists(mixed $offset): bool
    {
        return isset($this->data[$offset]);
    }

    #[Override]
    public function offsetGet(mixed $offset): mixed
    {
        return $this->wrap($this->data[$offset] ?? null);
    }

    #[Override]
    public function offsetSet(mixed $offset, mixed $value): void
    {
    }

    #[Override]
    public function offsetUnset(mixed $offset): void
    {
    }

    private function wrap(mixed $value): mixed
    {
        return is_array($value) ? new self($value) : $value;
    }
}
