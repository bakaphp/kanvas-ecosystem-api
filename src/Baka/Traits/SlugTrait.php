<?php
declare(strict_types=1);
namespace Baka\Traits;
use Illuminate\Support\Str;

trait SlugTrait
{
    /**
     * Boot function from laravel.
     *
     * @return void
     */
    public static function bootSlugTrait()
    {
        static::creating(function ($model) {
            $model->slug = $model->slug ?? Str::slug($model->name);
        });
    }
}
