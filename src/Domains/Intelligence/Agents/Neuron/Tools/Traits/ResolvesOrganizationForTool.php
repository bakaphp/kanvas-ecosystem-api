<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Traits;

use Kanvas\Guild\Organizations\Models\Organization;
use Kanvas\Guild\Organizations\Services\OrganizationNameNormalizerService;
use Throwable;

/**
 * Resolves a customer organization by id or by name for a tool, disambiguating when a name matches
 * more than one. Requires HasKanvasContext ($app, $company). Returns the Organization, or an
 * LLM-facing array (error / candidate list) the caller returns verbatim.
 */
trait ResolvesOrganizationForTool
{
    /**
     * @return Organization|array<string, mixed>
     */
    protected function resolveOrganization(?int $organizationId, ?string $organizationName): Organization|array
    {
        if ($organizationId !== null) {
            try {
                /** @var Organization $organization */
                $organization = Organization::getByIdFromCompanyApp($organizationId, $this->company, $this->app);

                return $organization;
            } catch (Throwable) {
                return ['error' => "No organization found with id {$organizationId} in this company."];
            }
        }

        $name = trim((string) $organizationName);
        if ($name === '') {
            return ['error' => 'Provide an organization_id or an organization_name.'];
        }

        $needle = OrganizationNameNormalizerService::normalize($name);
        $term = $needle !== '' ? $needle : $name;

        $matches = Organization::query()
            ->fromApp($this->app)
            ->fromCompany($this->company)
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
                ])->all(),
            ];
        }

        return $matches->first();
    }
}
