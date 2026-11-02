<?php

declare(strict_types=1);

namespace Kanvas\Workflow\Rules\Support;

use ArrayAccess;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Enumerable;
use Kanvas\Templates\Support\PlainData;
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
     * Wrap every entry in the ExpressionLanguage values map: arrays become dot-navigable and models
     * become an ExpressionModel. Only navigation changes, never comparison.
     *
     * @param array<array-key, mixed> $values
     *
     * @return array<array-key, mixed>
     */
    public static function wrapValues(array $values): array
    {
        return array_map(self::wrapValue(...), $values);
    }

    /**
     * Only a model (through ExpressionModel) can take a method call. Any other object is flattened, so
     * an allowlisted name such as get() never reaches a client or cache that happens to be in context.
     */
    public static function wrapValue(mixed $value): mixed
    {
        return match (true) {
            is_array($value) => new self($value),
            $value instanceof Model => new ExpressionModel($value),
            $value instanceof Enumerable => new self($value->all()),
            $value instanceof DateTimeInterface, ! is_object($value) => $value,
            default => self::wrapValue(PlainData::from($value)),
        };
    }

    public function __get(string $name): mixed
    {
        return self::wrapValue($this->data[$name] ?? null);
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
        return self::wrapValue($this->data[$offset] ?? null);
    }

    #[Override]
    public function offsetSet(mixed $offset, mixed $value): void
    {
    }

    #[Override]
    public function offsetUnset(mixed $offset): void
    {
    }
}
