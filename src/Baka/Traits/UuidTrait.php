<?php

declare(strict_types=1);

namespace Baka\Traits;

use Illuminate\Support\Str;

trait UuidTrait
{
    public static function bootUuidTrait(): void
    {
        static::creating(function ($model) {
            $model->uuid = $model->uuid ?? (string) Str::uuid7();
        });
    }
}
