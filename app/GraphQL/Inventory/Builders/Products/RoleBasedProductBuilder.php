<?php

declare(strict_types=1);

namespace App\GraphQL\Inventory\Builders\Products;

use Illuminate\Database\Eloquent\Builder;
use Kanvas\Apps\Models\Apps;

class RoleBasedProductBuilder
{
    /**
     * Apply role-based company scoping to product queries.
     *
     * - Admins see all products
     * - Providers see only their company's products
     * - Regular users see all products (marketplace view)
     */
    public function applyRoleScope(Builder $query, array $args): Builder
    {
        $user = auth()->user();
        $app = app(Apps::class);
        $platformCompanyId = $app->get('B2B_MAIN_COMPANY_ID');

        // Admin sees all products
        if ($user->isAdmin()) {
            return $query;
        }

        // Provider sees only their company's products
        $currentCompanyId = $user->getCurrentCompany()->getId();
        if ($currentCompanyId !== $platformCompanyId) {
            return $query->where('products.companies_id', $currentCompanyId);
        }

        // Regular users see all products (marketplace view)
        return $query;
    }
}
