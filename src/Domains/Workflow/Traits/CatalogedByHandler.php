<?php

declare(strict_types=1);

namespace Kanvas\Workflow\Traits;

use Illuminate\Database\Eloquent\Model;

/**
 * `actions` is a discovered catalog keyed on `model_name` — one row per handler class, enforced by a
 * unique index.
 *
 * Callers do not all know that. The common shape is `firstOrCreate(['name' => 'Create People',
 * 'model_name' => SomeJob::class])`, which matches on BOTH columns: the moment the sync gives that
 * handler a different display name, the composite lookup misses, the insert fires, and the unique
 * index rejects it. Before the index existed the same call quietly added another duplicate — which is
 * how the table reached ~1850 rows for a single handler.
 *
 * So the lookup is normalized here rather than at twenty-odd call sites: when `model_name` is named,
 * it alone identifies the row and everything else is treated as values to set on create. That keeps
 * both the existing callers and any future one correct without either having to know the rule.
 */
trait CatalogedByHandler
{
    /**
     * Resolved through `static::query()` rather than `parent::firstOrCreate()`: Eloquent defines
     * `firstOrCreate` on the Builder, not the Model, so `parent::` falls through `__callStatic` back
     * into this override and the recursion segfaults PHP rather than raising anything readable.
     *
     * @param array<string, mixed> $attributes
     * @param array<string, mixed> $values
     */
    public static function firstOrCreate(array $attributes = [], array $values = []): Model
    {
        if (! array_key_exists('model_name', $attributes)) {
            return static::query()->firstOrCreate($attributes, $values);
        }

        $handler = $attributes['model_name'];
        unset($attributes['model_name']);

        return static::query()->firstOrCreate(
            ['model_name' => $handler],
            [...$attributes, ...$values],
        );
    }
}
