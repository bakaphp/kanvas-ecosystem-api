<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Traits;

use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Organizations\Models\Organization;

/**
 * Turns the contact NAME an agent was given into the People at that customer Organization, or into
 * the structured refusal that tells the model to disambiguate.
 *
 * The search is scoped to the organization's own contacts rather than the whole company on purpose:
 * the name is LLM-supplied and therefore prompt-injectable, and a company-wide match would happily
 * address a quote to a person who works for a different customer.
 *
 * The tenant is asserted on the People rows themselves, not inherited from the tenant-scoped
 * organization — `organizations_peoples` is a raw-FK pivot with no apps_id of its own that connector
 * imports and people merges write, so a row on it can point outside the tenant.
 *
 * Refuses a close call rather than picking one, mirroring ResolvesCustomerForTool — the contact is
 * who the document is addressed to, so a wrong pick is a document sent to the wrong human.
 */
trait ResolvesOrganizationContactForTool
{
    use MatchesNameTerms;

    /**
     * @return People|array{reason: string, message: string}
     */
    protected function resolveOrganizationContactOrError(
        Organization $organization,
        string $contactName,
        string $disambiguationHint = 'Ask the user which one they meant.',
    ): People|array {
        $matches = $this->scopeToNameMatch(
            $organization->peoples()
                ->getQuery()
                ->where('peoples.is_deleted', 0)
                ->fromApp($this->app)
                ->fromCompany($this->company),
            [
                'peoples.name',
                'peoples.firstname',
                'peoples.middlename',
                'peoples.lastname',
            ],
            $contactName,
        )
            ->select('peoples.*')
            ->distinct()
            ->limit(10)
            ->get();

        if ($matches->count() === 1) {
            /** @var People $contact */
            $contact = $matches->first();

            return $contact;
        }

        if ($matches->isEmpty()) {
            return [
                'reason' => 'contact_not_found',
                'message' => "No contact matching \"{$contactName}\" is linked to {$organization->name}. "
                    . 'Ask the user for the exact name as it is on file, or retry without contact_name to '
                    . 'address the document to the organization itself.',
            ];
        }

        return [
            'reason' => 'contact_ambiguous',
            'message' => "\"{$contactName}\" could match more than one contact at {$organization->name}: "
                . implode(', ', $matches->map(static fn (People $person): string => $person->getDisplayName())->all())
                . '. ' . $disambiguationHint,
        ];
    }
}
