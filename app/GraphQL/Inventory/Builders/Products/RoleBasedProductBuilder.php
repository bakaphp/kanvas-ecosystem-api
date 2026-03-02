<?php

declare(strict_types=1);

namespace App\GraphQL\Inventory\Builders\Products;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Users\Contracts\UserInterface;
use Illuminate\Database\Eloquent\Builder;

class RoleBasedProductBuilder
{
    public function __construct(
        protected readonly UserInterface $user,
        protected readonly CompanyInterface $company,
        protected readonly AppInterface $app,
    ) {
    }

    /**
     * Apply role-based company scoping to product queries.
     *
     * - Admins see all products
     * - Providers see only their company's products
     * - Regular users see all products (marketplace view)
     */
    public function applyRoleScope(Builder $query, array $args): Builder
    {
        $platformCompanyId = $this->app->get('B2B_MAIN_COMPANY_ID');

        // Admin sees all products
        if ($this->user->isAdmin()) {
            return $query;
        }

        // Provider sees only their company's products
        if ($this->company->getId() !== $platformCompanyId) {
            return $query->where('products.companies_id', $this->company->getId());
        }

        // Regular users see all products (marketplace view)
        return $query;
    }
}
