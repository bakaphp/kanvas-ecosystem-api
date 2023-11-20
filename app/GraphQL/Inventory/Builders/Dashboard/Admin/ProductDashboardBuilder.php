<?php

declare(strict_types=1);

namespace App\GraphQL\Inventory\Builders\Dashboard\Admin;

use Illuminate\Support\Facades\DB;
use Kanvas\Apps\Models\Apps;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Inventory\Variants\Models\Variants;
use Kanvas\Inventory\Variants\Models\VariantsWarehouses;

class ProductDashboardBuilder
{
    /**
     * all.
     */
    public function getCompanyInfo($rootValue, array $request): array
    {
        $app = app(Apps::class);

        $result = VariantsWarehouses::query()
            ->select(
                'status.id as statusId',
                'status.name as statusName',
                'status.slug as statusSlug',
                'status.companies_id as statusCompaniesId',
                DB::raw('COUNT(*) as totalAmount')
            )
            ->join('status', function ($join) {
                $join->on('products_variants_warehouses.status_id', '=', 'status.id');
            })
            ->join('warehouses', function ($join) {
                $join->on('products_variants_warehouses.warehouses_id', '=', 'warehouses.id');
            })
            ->where('products_variants_warehouses.is_deleted', 0)
            ->where('status.is_deleted', 0)
            ->groupBy('status.id')
        ->get();

        return [
            'totalProducts' => Products::fromApp($app)->notDeleted()->where('is_published', 1)->count(),
            'totalVariants' => Variants::fromApp($app)->notDeleted()->count(),
            'productStatus' => $result->toArray() ?? [],
        ];
    }
}
