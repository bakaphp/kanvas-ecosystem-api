<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Tools\Traits\Guild;

use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Organizations\Models\Organization;

trait LinksOrganizationByEmailDomain
{
    /**
     * Linked after the person is saved rather than passed as an organization name: that path goes
     * through CreateOrganizationAction, which normalizes the name and firstOrCreates, so "Acme, Inc."
     * would come back as a second "Acme" instead of the organization the domain points at.
     *
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
