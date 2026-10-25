<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Apollo\Services\Concerns;

use Kanvas\Companies\Support\CompanyScope;

/**
 * `crm` is the tenant company and `company` the person's employer; the two must never merge.
 * Requires a `$company` property typed `Companies|CompanyScope` on the host.
 */
trait LabelsTenantCompany
{
    private function tenantName(int $companiesId): string
    {
        if ($this->company instanceof CompanyScope) {
            return (string) ($this->company->companyName($companiesId) ?? $this->ownCompanyName());
        }

        return $this->ownCompanyName();
    }

    /**
     * @return array{tenant_company_id: int}|array{}
     */
    private function tenantId(int $companiesId): array
    {
        return CompanyScope::spansCompanies($this->company) ? ['tenant_company_id' => $companiesId] : [];
    }

    private function ownCompanyName(): string
    {
        return (string) ($this->company instanceof CompanyScope ? $this->company->ownCompany->name : $this->company->name);
    }
}
