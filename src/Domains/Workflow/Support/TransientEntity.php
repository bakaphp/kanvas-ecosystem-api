<?php

declare(strict_types=1);

namespace Kanvas\Workflow\Support;

use Illuminate\Database\Eloquent\Model;

/**
 * A model that has no row yet, carried through a stored workflow. The workflow serializer turns
 * every Eloquent model into a ModelIdentifier and restores it with `firstOrFail()`, so an unsaved
 * id-0 subject — a pull by external id, before the record exists — would throw on restore. This
 * keeps the class and attributes instead and rebuilds the same unsaved model on the other side.
 */
final readonly class TransientEntity
{
    /**
     * @param class-string<Model> $class
     * @param array<string, mixed> $attributes
     */
    public function __construct(
        public string $class,
        public array $attributes,
    ) {
    }

    public static function fromModel(Model $model): self
    {
        return new self($model::class, $model->getAttributes());
    }

    /**
     * What to hand the workflow serializer: a saved model as-is, an unsaved one wrapped.
     */
    public static function wrap(Model $model): Model|self
    {
        return $model->exists ? $model : self::fromModel($model);
    }

    public static function unwrap(mixed $value): mixed
    {
        return $value instanceof self ? $value->toModel() : $value;
    }

    public function toModel(): Model
    {
        return new $this->class()->forceFill($this->attributes);
    }
}
