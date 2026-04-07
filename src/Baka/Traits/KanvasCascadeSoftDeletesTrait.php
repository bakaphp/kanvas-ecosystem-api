<?php

declare(strict_types=1);

namespace Baka\Traits;

use Illuminate\Database\Eloquent\Relations\Relation;
use LogicException;

/**
 * Cascade soft deletes for Kanvas models.
 *
 * Works with KanvasModelTrait::softDelete() which fires `softDeleting`/`softDeleted` events
 * instead of Laravel's `deleting` event used by dyrynda/laravel-cascade-soft-deletes.
 *
 * Usage: Add the trait and define $cascadeSoftDeletes on the model:
 *
 *     use KanvasCascadeSoftDeletesTrait;
 *     protected array $cascadeSoftDeletes = ['deployments', 'histories'];
 */
trait KanvasCascadeSoftDeletesTrait
{
    protected static function bootKanvasCascadeSoftDeletesTrait(): void
    {
        static::registerModelEvent('softDeleting', function ($model) {
            $model->runKanvasCascadeSoftDeletes();
        });
    }

    protected function runKanvasCascadeSoftDeletes(): void
    {
        foreach ($this->getKanvasCascadeSoftDeletes() as $relationship) {
            $this->validateKanvasCascadeRelationship($relationship);

            if (! $this->{$relationship}()->exists()) {
                continue;
            }

            $this->{$relationship}()->update(['is_deleted' => 1]);
        }
    }

    protected function getKanvasCascadeSoftDeletes(): array
    {
        return $this->cascadeSoftDeletes ?? [];
    }

    protected function validateKanvasCascadeRelationship(string $relationship): void
    {
        if (! method_exists($this, $relationship) || ! $this->{$relationship}() instanceof Relation) {
            throw new LogicException(
                sprintf('%s [%s] must be a valid relationship method.', static::class, $relationship)
            );
        }
    }
}
