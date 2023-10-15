<?php

declare(strict_types=1);

namespace App\GraphQL\Inventory\Builders\Dashboard;

use Illuminate\Support\Facades\DB;
use Kanvas\Inventory\Products\Models\Products;

class ProductDashboardBuilder
{
    /**
     * all.
     */
    public function getCompanyInfo($rootValue, array $request): array
    {
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $result = DB::connection('inventory')
            ->table('products_variants_warehouse_status_history as h')
                ->select('s.id', 's.name', DB::raw('COUNT(*) as total_amount'))
                ->join('status as s', 'h.status_id', '=', 's.id')
                ->where('h.is_deleted', 0)
                ->where('s.companies_id', $company->getId())
                ->where('s.is_deleted', 0)
                ->groupBy('s.id', 's.name')
            ->get();

        $resultArray = $result->pluck('total_amount', 'name')->toArray();

        return [
            'total_products' => Products::fromCompany($company)->where('is_published', 1)->count(),
            'status' => $resultArray ?? [],
        ];
    }
}
