<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Tools\Traits\Guild;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Users\Contracts\UserInterface;
use Kanvas\Exceptions\ModelNotFoundException;
use Kanvas\Guild\Customers\DataTransferObject\Address;
use Kanvas\Guild\Customers\DataTransferObject\Contact;
use Kanvas\Guild\Customers\DataTransferObject\People;
use Kanvas\Guild\Leads\Actions\CreateLeadAction;
use Kanvas\Guild\Leads\DataTransferObject\Lead as LeadData;
use Kanvas\Guild\Organizations\DataTransferObject\Organization as OrganizationData;
use Kanvas\Guild\Organizations\Models\Organization;
use Spatie\LaravelData\DataCollection;
use Throwable;

trait CreatesLeadTrait
{
    protected function createLead(
        AppInterface $app,
        CompanyInterface $company,
        UserInterface $user,
        string $title,
        string $firstname,
        ?string $lastname = null,
        ?string $email = null,
        ?string $phone = null,
        ?string $description = null,
        int $leadTypeId = 0,
        int $leadSourceId = 0,
        ?int $organizationId = null,
        ?string $organizationName = null,
        bool $isPublished = true,
    ): array {
        // Wrap the full body — including `$company->defaultBranch`, DTO
        // construction, and Action execute — so any data-integrity issue
        // (missing default branch, stale company FK, missing pipeline stage,
        // etc.) returns a structured error to the LLM instead of bubbling.
        try {
            $branch = $company->defaultBranch;

            if ($branch === null) {
                return [
                    'error' => 'Company has no default branch configured. '
                        . 'Cannot create a lead until the tenant has a default companies_branches row.',
                ];
            }

            // organization_id is LLM-supplied, so it can be hallucinated or belong to another
            // tenant. Resolve it before anything is written; a foreign id must fail the call, not
            // land on the lead row.
            $organization = null;
            if ($organizationId !== null) {
                try {
                    /** @var Organization $organization */
                    $organization = Organization::getByIdFromCompanyApp($organizationId, $company, $app);
                } catch (ModelNotFoundException) {
                    return [
                        'error' => "Organization {$organizationId} does not exist for this company. "
                            . 'Do not invent an organization_id. Look it up first, or pass organization_name '
                            . 'and the organization will be created if it is new.',
                    ];
                }
            }

            $newOrganizationName = trim((string) $organizationName);

            $contacts = [];
            if (filled($email)) {
                $contacts[] = new Contact(value: $email);
            }
            if (filled($phone)) {
                $contacts[] = new Contact(value: $phone);
            }

            $people = new People(
                app: $app,
                branch: $branch,
                user: $user,
                firstname: $firstname,
                contacts: Contact::collect($contacts, DataCollection::class),
                address: Address::collect([], DataCollection::class),
                lastname: $lastname ?: null,
            );

            $leadData = new LeadData(
                app: $app,
                branch: $branch,
                user: $user,
                title: $title,
                pipeline_stage_id: (int) $company->get('agent_lead_pipeline_stage_id', 0),
                people: $people,
                description: $description,
                type_id: $leadTypeId,
                source_id: $leadSourceId,
                // Name-only path: CreateLeadAction find-or-creates the org, stamps it on the lead,
                // AND adds the person to it. The id path can't go through here — see below.
                organization: $organization === null && $newOrganizationName !== ''
                    ? new OrganizationData(
                        company: $company,
                        user: $user,
                        app: $app,
                        name: $newOrganizationName,
                    )
                    : null,
            );

            $lead = new CreateLeadAction($leadData)->execute();

            // An id names ONE exact organization, and CreateLeadAction resolves by normalized name —
            // which would land on a same-named sibling. Link it here instead, doing what the action
            // does for the name path: stamp the lead and make the person a member.
            if ($organization !== null) {
                $lead->organization_id = $organization->getId();
            }

            if (! $isPublished) {
                $lead->is_published = 0;
            }

            if ($lead->isDirty()) {
                $lead->saveOrFail();
            }

            $organization?->addPeople($lead->people);
        } catch (Throwable $e) {
            return [
                'error' => $e::class,
                'message' => "Failed to create lead: {$e->getMessage()}",
            ];
        }

        return [
            'lead_id' => $lead->getId(),
            'title' => $lead->title,
            'people_id' => $lead->people_id,
            'organization_id' => $lead->organization_id,
            'message' => "Lead '{$lead->title}' created successfully.",
        ];
    }
}
