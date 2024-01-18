<?php

declare(strict_types=1);

namespace App\GraphQL\Inventory\Builders\Dashboard;

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
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        $app = app(Apps::class);

        $result = VariantsWarehouses::query()
            ->select(
                'status.id as status_id',
                'status.name as status_name',
                'status.slug as status_slug',
                DB::raw('COUNT(*) as total_amount'),
                'warehouses.name as warehouses_name',
                'warehouses.id as warehouses_id'
            )
            ->join('status', function ($join) {
                $join->on('products_variants_warehouses.status_id', '=', 'status.id');
            })
            ->join('warehouses', function ($join) {
                $join->on('products_variants_warehouses.warehouses_id', '=', 'warehouses.id');
            })
            ->where('products_variants_warehouses.is_deleted', 0)
            ->where('status.is_deleted', 0)
            ->where('warehouses.companies_id', $company->getId())
            ->groupBy('warehouses.id', 'status.id')
        ->get();

        return [
            'total_products' => Products::fromCompany($company)->notDeleted()->where('is_published', 1)->count(),
            'total_variants' => Variants::fromCompany($company)->notDeleted()->count(),
            'product_status' => $result->toArray() ?? [],
        ];
    }
}
