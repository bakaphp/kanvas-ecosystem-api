<?php

declare(strict_types=1);

namespace Baka\Traits;

use Illuminate\Support\Str;

trait UuidTrait
{
    public static function bootUuidTrait(): void
    {
        static::creating(function ($model) {
            $model->generateUuidIfMissing();
        });
    }

    /**
     * Call this before `saveQuietly()`.
     *
     * `saveQuietly()` is `withoutEvents()`, so the `creating` hook above never runs and the row
     * lands with a null uuid — silently, because nothing reads it until something else does.
     * A bulk importer that skips events to stay off the search index still needs the uuid, and
     * it must come from here rather than a second `Str::uuid7()` call site.
     */
    public function generateUuidIfMissing(): static
    {
        $this->uuid ??= (string) Str::uuid7();

        return $this;
    }
}
