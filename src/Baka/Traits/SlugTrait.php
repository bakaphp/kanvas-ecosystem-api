<?php

declare(strict_types=1);

namespace Baka\Traits;

use Baka\Contracts\CompanyInterface;
use Baka\Enums\StateEnums;
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

        static::updating(function ($model) {
            $model->slug = $model->slug ?? Str::slug($model->name);
        });
    }

    /**
     * Get Model by Slug firstOrFail.
     */
    public static function getBySlugOrFail(string $slug, CompanyInterface $company): self
    {
        return self::where('slug', $slug)
            ->where('companies_id', $company->getId())
            ->where('is_deleted', StateEnums::NO->getValue())
            ->firstOrFail();
    }
    /**
     * Get Model by Slug.
     */
    public static function getBySlug(string $slug, CompanyInterface $company): ?self
    {
        return self::where('slug', $slug)
            ->where('companies_id', $company->getId())
            ->where('is_deleted', StateEnums::NO->getValue())
            ->first();
    }

}
