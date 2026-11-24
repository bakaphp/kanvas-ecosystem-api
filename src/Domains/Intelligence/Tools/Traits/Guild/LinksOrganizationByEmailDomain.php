<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Tools\Traits\Guild;

use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Organizations\Models\Organization;

trait LinksOrganizationByEmailDomain
{
    /**
     * @return array<string, mixed>
     */
    protected function organizationFromEmailDomain(People $person): array
    {
        $matches = Organization::linkPersonByEmailDomain($person);

        if ($matches->count() === 1) {
            /** @var Organization $organization */
            $organization = $matches->first();

            return [
                'organization' => ['organization_id' => $organization->getId(), 'name' => $organization->name],
                'organization_source' => 'email_domain',
            ];
        }

        if ($matches->count() > 1) {
            return [
                'organization_candidates' => $matches
                    ->map(fn (Organization $org): array => ['organization_id' => $org->getId(), 'name' => $org->name])
                    ->all(),
                'note' => 'Several organizations use this email domain, so none was linked. Ask the user which one '
                    . 'this person works for before linking them.',
            ];
        }

        return [];
    }
}
