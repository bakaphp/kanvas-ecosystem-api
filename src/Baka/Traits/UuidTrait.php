<?php
declare(strict_types=1);
namespace Baka\Traits;

use Illuminate\Support\Str;

trait UuidTrait
{
    /**
     * Boot function from laravel.
     *
     * @return void
     */
    public static function bootUuidTrait()
    {
        static::creating(function ($model) {
            $model->uuid = $model->uuid ?? Str::uuid();
        });
    }

    /**
     * Generate a new id.
     *
     * @return string
     */
    public function generateNewId() : string
    {
        return uniqid();
    }
}
