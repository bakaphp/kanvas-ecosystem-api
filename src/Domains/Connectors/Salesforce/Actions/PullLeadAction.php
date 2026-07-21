<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Salesforce\Actions;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Kanvas\Connectors\Salesforce\Enums\CustomFieldEnum;
use Kanvas\Guild\Customers\DataTransferObject\Address;
use Kanvas\Guild\Customers\DataTransferObject\Contact;
use Kanvas\Guild\Customers\DataTransferObject\People as PeopleData;
use Kanvas\Guild\Deals\Models\Deal;
use Kanvas\Guild\Leads\Actions\SyncLeadByThirdPartyCustomFieldAction;
use Kanvas\Guild\Leads\DataTransferObject\Lead as LeadData;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Leads\Models\LeadStatus;
use Kanvas\Guild\Organizations\Models\Organization;
use Kanvas\Guild\Pipelines\Models\Pipeline;
use Spatie\LaravelData\DataCollection;

class PullLeadAction
{
    public function __construct(
        protected AppInterface $app,
        protected CompanyInterface $company,
        protected array $payload,
        protected string $salesforceId,
    ) {
    }

    public function execute(): Lead
    {
        $branch = $this->company->defaultBranch ?? $this->company->user->getCurrentCompany()->branch;
        $firstName = (string) ($this->payload['FirstName'] ?? '');
        $lastName = (string) ($this->payload['LastName'] ?? 'Unknown');

        $contacts = [];
        if (! empty($this->payload['Email'])) {
            $contacts[] = ['value' => $this->payload['Email'], 'contacts_types_id' => 1, 'weight' => 0];
        }
        if (! empty($this->payload['Phone'])) {
            $contacts[] = ['value' => $this->payload['Phone'], 'contacts_types_id' => 2, 'weight' => 0];
        }

        $leadStatus = LeadStatus::query()
            ->fromApp($this->app)
            ->fromCompany($this->company)
            ->notDeleted()
            ->firstOrCreate(
                ['name' => strtolower((string) ($this->payload['Status'] ?? 'new'))],
                ['apps_id' => $this->app->getId(), 'companies_id' => $this->company->getId(), 'is_default' => 0],
            );

        $pipelineStage = Pipeline::query()
            ->fromApp($this->app)
            ->fromCompany($this->company)
            ->where('is_default', 1)
            ->first()
            ?->stages()
            ->first();

        $leadData = new LeadData(
            app: $this->app,
            branch: $branch,
            user: $this->company->user,
            title: trim($firstName . ' ' . $lastName),
            pipeline_stage_id: $pipelineStage?->getId() ?? 0,
            people: new PeopleData(
                app: $this->app,
                branch: $branch,
                user: $this->company->user,
                firstname: $firstName !== '' ? $firstName : $lastName,
                contacts: Contact::collect($contacts, DataCollection::class),
                address: Address::collect([], DataCollection::class),
                lastname: $lastName,
                // SyncLeadByThirdPartyCustomFieldAction dedupes the embedded People by its own
                // custom field too (not just the Lead's) — reuse the same Salesforce Lead id so
                // repeated syncs of the same Lead update the same Person instead of creating one
                // every time.
                custom_fields: [
                    CustomFieldEnum::SALESFORCE_LEAD_ID->value => $this->salesforceId,
                ],
            ),
            status_id: $leadStatus->getId(),
            description: $this->payload['Description'] ?? null,
            custom_fields: [
                CustomFieldEnum::SALESFORCE_LEAD_ID->value => $this->salesforceId,
            ],
            runWorkflow: false,
        );

        $lead = new SyncLeadByThirdPartyCustomFieldAction($leadData)->execute();

        $this->handleConversion($lead);

        return $lead;
    }

    // Salesforce Leads carry no Account/Contact/Opportunity relationship until converted — the
    // Outbound Message that fires on the conversion edit is what actually delivers IsConverted +
    // the Converted*Id fields, so any of this only has something to link once that follow-up
    // notification arrives (add IsConverted, ConvertedAccountId, ConvertedContactId,
    // ConvertedOpportunityId to the Lead Outbound Message's "Fields to Send").
    private function handleConversion(Lead $lead): void
    {
        if (($this->payload['IsConverted'] ?? null) !== 'true') {
            return;
        }

        $this->linkConvertedOrganization($lead);
        $this->linkConvertedContact($lead);
        $this->linkConvertedOpportunity($lead);
    }

    private function linkConvertedOrganization(Lead $lead): void
    {
        $convertedAccountId = $this->payload['ConvertedAccountId'] ?? null;
        if (empty($convertedAccountId)) {
            return;
        }

        /** @var Organization|null $organization */
        $organization = Organization::getByCustomFieldTransactionSafe(
            CustomFieldEnum::SALESFORCE_ACCOUNT_ID->value,
            (string) $convertedAccountId,
            $this->company,
        );

        if ($organization === null || $lead->organization_id === $organization->getId()) {
            return;
        }

        $lead->organization_id = $organization->getId();
        $lead->disableWorkflows();
        $lead->saveOrFail();
    }

    // Salesforce hides the Lead after conversion and continues the relationship as a Contact —
    // tagging this same People record with its new Contact id means the Contact's own Outbound
    // Message (keyed by SALESFORCE_CONTACT_ID) updates this Person instead of creating a duplicate.
    private function linkConvertedContact(Lead $lead): void
    {
        $convertedContactId = $this->payload['ConvertedContactId'] ?? null;
        if (empty($convertedContactId) || $lead->people === null) {
            return;
        }

        if ($lead->people->get(CustomFieldEnum::SALESFORCE_CONTACT_ID->value) === (string) $convertedContactId) {
            return;
        }

        $lead->people->set(CustomFieldEnum::SALESFORCE_CONTACT_ID->value, (string) $convertedContactId);
    }

    // Only links an already-synced Deal (found by the Opportunity's own Outbound Message) back
    // to this Lead — if that Opportunity hasn't arrived yet, there's nothing to link yet and
    // nothing re-triggers this once it does.
    private function linkConvertedOpportunity(Lead $lead): void
    {
        $convertedOpportunityId = $this->payload['ConvertedOpportunityId'] ?? null;
        if (empty($convertedOpportunityId)) {
            return;
        }

        /** @var Deal|null $deal */
        $deal = Deal::getByCustomFieldTransactionSafe(
            CustomFieldEnum::SALESFORCE_OPPORTUNITY_ID->value,
            (string) $convertedOpportunityId,
            $this->company,
        );

        if ($deal === null || $deal->leads_id === $lead->getId()) {
            return;
        }

        $deal->leads_id = $lead->getId();
        $deal->disableWorkflows();
        $deal->saveOrFail();
    }
}
