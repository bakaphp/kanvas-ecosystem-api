<?php

declare(strict_types=1);

namespace App\GraphQL\Ecosystem\Queries\Companies;

class CompanySettingQuery
{
    /**
     * Get user from the current company.
     */
    public function getAllSettings($rootValue, array $request): array
    {
        $company = auth()->user()->getCurrentCompany();

        return [
            'name'        => $company->name,
            'description' => $company->description,
            'settings'    => $company->getAllSettings(onlyPublicSettings: true),
        ];
    }
}
