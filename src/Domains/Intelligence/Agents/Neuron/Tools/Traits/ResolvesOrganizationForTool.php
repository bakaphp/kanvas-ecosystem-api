<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Traits;

use Kanvas\Companies\Models\Companies;
use Kanvas\Companies\Support\CompanyScope;
use Kanvas\Guild\Organizations\Models\Organization;
use Kanvas\Guild\Organizations\Services\OrganizationNameNormalizerService;

/**
 * Resolves a customer organization by id or by name for a tool, disambiguating when a name matches
 * more than one. Requires HasKanvasContext ($app, $company). Returns the Organization, or an
 * LLM-facing array (error / candidate list) the caller returns verbatim.
 */
trait ResolvesOrganizationForTool
{
    use HasKanvasContext;

    /**
     * @return Organization|array<string, mixed>
     */
    protected function resolveOrganization(?int $organizationId, ?string $organizationName): Organization|array
    {
        if (! $this->hasTenantContext()) {
            return $this->tenantContextMissingError('organization');
        }

        return $this->findOrganization($organizationId, $organizationName, $this->company);
    }

    /**
     * Read-only variant inside the agent's read scope; write tools keep resolveOrganization().
     *
     * @return Organization|array<string, mixed>
     */
    protected function resolveOrganizationInReadScope(?int $organizationId, ?string $organizationName): Organization|array
    {
        if (! $this->hasTenantContext()) {
            return $this->tenantContextMissingError('organization');
        }

        return $this->findOrganization($organizationId, $organizationName, $this->readCompanyScope());
    }

    /**
     * @return Organization|array<string, mixed>
     */
    private function findOrganization(
        ?int $organizationId,
        ?string $organizationName,
        Companies|CompanyScope $company
    ): Organization|array {
        if ($organizationId !== null) {
            /** @var Organization|null $organization */
            $organization = Organization::query()
                ->where('id', $organizationId)
                ->fromApp($this->app)
                ->fromCompany($company)
                ->notDeleted()
                ->first();

            return $organization
                ?? $this->belongsToAnotherCompanyError(Organization::class, $organizationId, 'organization')
                ?? ['error' => "No organization found with id {$organizationId} in this company."];
        }

        $name = trim((string) $organizationName);
        if ($name === '') {
            return ['error' => 'Provide an organization_id or an organization_name.'];
        }

        $needle = OrganizationNameNormalizerService::normalize($name);
        $term = $needle !== '' ? $needle : $name;

        $matches = Organization::query()
            ->fromApp($this->app)
            ->fromCompany($company)
            ->notDeleted()
            ->where('name', 'like', '%' . $term . '%')
            ->limit(10)
            ->get();

        if ($matches->isEmpty()) {
            return ['error' => "No organization found matching \"{$name}\"."];
        }

        if ($matches->count() > 1) {
            return [
                'needs_disambiguation' => true,
                'message' => 'More than one organization matches — ask the user which one, then call again with organization_id.',
                'candidates' => $matches->map(fn (Organization $org): array => [
                    'organization_id' => $org->getId(),
                    'name' => $org->name,
                    ...($company instanceof CompanyScope ? $company->tenantLabelFor((int) $org->companies_id) : []),
                ])->all(),
            ];
        }

        return $matches->first();
    }
}
