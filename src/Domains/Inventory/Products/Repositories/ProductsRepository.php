<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Products\Repositories;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Traits\SearchableTrait;
use Carbon\Carbon;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
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
            //->where('p.is_published', '=', 1)
            ->where('p.is_deleted', '=', 0)
            ->where('p.companies_id', '=', $company->getId())
            ->where('p.apps_id', '=', $app->getId());
    }

    public static function getProductWithUnPassedEndDate(AppInterface $app, CompanyInterface $company): Builder
    {
        return Products::from('products as p')
            ->withoutGlobalScopes()  // Disable global scopes
            ->join('products_attributes as pa', 'p.id', '=', 'pa.products_id')
            ->join('attributes as a', 'pa.attributes_id', '=', 'a.id')
            ->select('p.*')
            ->where('a.slug', '=', 'end-date')
            ->where('pa.value', '>', Carbon::now())
            ->where('p.is_published', '=', 1)
            ->where('p.is_deleted', '=', 0)
            ->where('p.companies_id', '=', $company->getId())
            ->where('p.apps_id', '=', $app->getId());
    }

    public static function getLowStockProducts(
        AppInterface $app,
        CompanyInterface $company,
        int $lowStockThreshold = 200,
        ?int $productTypeId = null
    ): Builder {
        $query = Products::from('products as p')
            ->withoutGlobalScopes() // Disable global scopes
            ->join('products_variants as v', 'p.id', '=', 'v.products_id')
            ->join('products_variants_warehouses as pvw', 'v.id', '=', 'pvw.products_variants_id')
            ->join('warehouses as w', 'pvw.warehouses_id', '=', 'w.id')
            ->select([
                'p.id as product_id',
                'p.name as product_name',
                'p.slug as product_slug',
                DB::raw('SUM(pvw.quantity) as total_stock_quantity'),
                DB::raw("GROUP_CONCAT(
                    CONCAT(v.name, ' (SKU: ', v.sku, ') - Qty: ', pvw.quantity) 
                    SEPARATOR ' | '
                ) as variants_breakdown"),
                DB::raw("GROUP_CONCAT(DISTINCT w.name SEPARATOR ', ') as warehouses"),
            ])
            ->where('p.is_deleted', '=', 0)
            ->where('v.is_deleted', '=', 0)
            ->where('pvw.is_deleted', '=', 0)
            ->where('w.is_deleted', '=', 0)
            ->where('p.is_published', '=', 1)
            ->where('v.is_published', '=', 1)
            ->where('p.companies_id', '=', $company->getId())
            ->where('p.apps_id', '=', $app->getId())
            ->groupBy(['p.id', 'p.name', 'p.slug'])
            ->havingRaw('total_stock_quantity < ?', [$lowStockThreshold])
            ->orderBy('total_stock_quantity', 'asc');

        // Add product type filter if provided
        if ($productTypeId !== null) {
            $query->where('p.products_types_id', '=', $productTypeId);
        }

        return $query;
    }
}
