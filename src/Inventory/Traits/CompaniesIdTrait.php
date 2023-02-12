<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Traits;

trait CompaniesIdTrait
{
    public static function bootCompaniesIdTrait()
    {
        static::creating(function ($model) {
            $model->companies_id = $model->companies_id ?? auth()->user()->default_company;
        });
    }
}
