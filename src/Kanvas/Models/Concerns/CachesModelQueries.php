<?php

declare(strict_types=1);

namespace Kanvas\Models\Concerns;

use GeneaLabs\LaravelModelCaching\Traits\Cachable;

/**
 * Model query caching, minus the duplicate invalidation the upstream trait does on every insert.
 *
 * Upstream binds `created`, `saved` and `deleted`. Laravel fires `created` and then `saved` on a
 * single insert, and each one runs a full tagged-cache flush — measured at ~60ms a piece against
 * Redis, so every insert pays ~120ms of invalidation for one row. `saved` always follows `created`
 * on an insert and also covers updates, so binding it alone invalidates exactly as often, half as
 * many times.
 */
trait CachesModelQueries
{
    use Cachable;

    public static function bootCachable(): void
    {
        static::saved(function ($instance) {
            $instance->checkCooldownAndFlushAfterPersisting($instance);
        });

        static::deleted(function ($instance) {
            $instance->checkCooldownAndFlushAfterPersisting($instance);
        });

        static::pivotSynced(function ($instance, $relationship) {
            $instance->checkCooldownAndFlushAfterPersisting($instance, $relationship);
        });

        static::pivotAttached(function ($instance, $relationship) {
            $instance->checkCooldownAndFlushAfterPersisting($instance, $relationship);
        });

        static::pivotDetached(function ($instance, $relationship) {
            $instance->checkCooldownAndFlushAfterPersisting($instance, $relationship);
        });

        static::pivotUpdated(function ($instance, $relationship) {
            $instance->checkCooldownAndFlushAfterPersisting($instance, $relationship);
        });
    }
}
