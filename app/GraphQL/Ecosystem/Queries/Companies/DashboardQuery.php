<?php

declare(strict_types=1);

namespace App\GraphQL\Ecosystem\Queries\Companies;

use Kanvas\Dashboard\Enums\DashboardEnum;

class DashboardQuery
{
    /**
     * Get user from the current company.
     *
     * @param mixed $rootValue
     */
    public function getDashboard($rootValue, array $request): array
    {
        $company = auth()->user()->getCurrentCompany();
        $fields = $company->get(DashboardEnum::DEFAULT_ENUM->value);
        return [
            "name" => "dashboard",
            "fields" => $fields
        ];
    }
}
