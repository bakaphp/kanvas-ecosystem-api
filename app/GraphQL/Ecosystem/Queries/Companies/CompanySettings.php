<?php

declare(strict_types=1);

namespace App\GraphQL\Ecosystem\Queries\Companies;

class CompanySettings
{
    /**
     * Get user from the current company.
     *
     * @param mixed $rootValue
     * @param array $request
     *
     * @return array
     */
    public function getAllSettings($rootValue, array $request): array
    {
        $company = auth()->user()->getCurrentCompany();

        return [
            'name' => $company->name,
            'description' => $company->description,
            'settings' => $company->getAllSettings(onlyPublicSettings: true),
        ];
    }
}
