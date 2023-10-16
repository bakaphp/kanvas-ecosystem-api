<?php

declare(strict_types=1);

namespace App\GraphQL\Inventory\Builders\Dashboard;

use Illuminate\Support\Facades\DB;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Inventory\Variants\Models\Variants;

class ProductDashboardBuilder
{
    /**
     * all.
     */
    public function getCompanyInfo($rootValue, array $request): array
    {
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $result = Variants::query()
            ->select('status.id', 'status.name', DB::raw('COUNT(*) as total_amount'))
            ->join('status', function ($join) {
                $join->on('products_variants.status_id', '=', 'status.id')
                    ->on('status.companies_id', '=', 'products_variants.companies_id');
            })
            ->where('products_variants.is_deleted', 0)
            ->where('status.is_deleted', 0)
            ->where('products_variants.companies_id', $company->getId())
            ->groupBy('status.id', 'status.name')
        ->get();

        $resultArray = $result->pluck('total_amount', 'name')->toArray();

        return [
            'total_products' => Products::fromCompany($company)->notDeleted()->where('is_published', 1)->count(),
            'product_status' => $resultArray ?? [],
        ];
    }
}
