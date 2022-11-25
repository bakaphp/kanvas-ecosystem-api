<?php
declare(strict_types=1);

namespace Kanvas\Inventory\Traits;

trait SetCompanyTrait
{
    public function bootSetCompany()
    {
        static::creating(function ($model) {
            $model->companies_id = $model->companies_id ?? auth()->user()->companies_id;
        });
    }
}
