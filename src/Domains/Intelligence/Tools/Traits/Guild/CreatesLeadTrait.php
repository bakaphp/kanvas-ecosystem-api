<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Tools\Traits\Guild;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Users\Contracts\UserInterface;
use Kanvas\Guild\Customers\DataTransferObject\Address;
use Kanvas\Guild\Customers\DataTransferObject\Contact;
use Kanvas\Guild\Customers\DataTransferObject\People;
use Kanvas\Guild\Leads\Actions\CreateLeadAction;
use Kanvas\Guild\Leads\DataTransferObject\Lead as LeadData;
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
            );

            $lead = new CreateLeadAction($leadData)->execute();

            if ($organizationId !== null) {
                $lead->organization_id = $organizationId;
                $lead->save();
            }
        } catch (Throwable $e) {
            return [
                'error' => $e::class,
                'message' => "Failed to create lead: {$e->getMessage()}",
            ];
        }

        return [
            'lead_id' => $lead->getId(),
            'title' => $lead->title,
            'organization_id' => $lead->organization_id,
            'message' => "Lead '{$lead->title}' created successfully.",
        ];
    }
}
