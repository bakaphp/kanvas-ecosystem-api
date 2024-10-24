<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Products\Repositories;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Traits\SearchableTrait;
use Carbon\Carbon;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Kanvas\Inventory\Products\Models\Products;

class ProductsRepository
{
    use SearchableTrait;

    public static function getModel(): Model
    {
        return new Products();
    }

    public static function getBySourceKey(string $key, string $id): Products
    {
        $key = $key . '_id';

        return Products::getByCustomField($key, $id);
    }

    public static function getProductsWithPassedEndDate(AppInterface $app, CompanyInterface $company): Builder
    {
        return Products::from('products as p')
            ->withoutGlobalScopes()  // Disable global scopes
            ->join('products_attributes as pa', 'p.id', '=', 'pa.products_id')
            ->join('attributes as a', 'pa.attributes_id', '=', 'a.id')
            ->select('p.*')
            ->where('a.slug', '=', 'end-date')
            ->where('pa.value', '<=', Carbon::now())
            ->where('p.is_published', '=', 1)
            ->where('p.is_deleted', '=', 0)
            ->where('p.companies_id', '=', $company->getId())
            ->where('p.apps_id', '=', $app->getId());
    }

    public static function getProductsWithPendingEndDate(AppInterface $app, CompanyInterface $company): Builder
    {
        return Products::from('products as p')
            ->withoutGlobalScopes()  // Disable global scopes
            ->join('products_attributes as pa', 'p.id', '=', 'pa.products_id')
            ->join('attributes as a', 'pa.attributes_id', '=', 'a.id')
            ->select('p.*')
            ->where('a.slug', '=', 'end-date')
            ->where('pa.value', '>', Carbon::now())
            ->where('p.is_published', '=', 0)
            ->where('p.is_deleted', '=', 0)
            ->where('p.companies_id', '=', $company->getId())
            ->where('p.apps_id', '=', $app->getId());
    }
}
