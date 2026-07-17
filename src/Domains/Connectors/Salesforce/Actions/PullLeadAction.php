<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Salesforce\Actions;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Kanvas\Connectors\Salesforce\Enums\CustomFieldEnum;
use Kanvas\Guild\Customers\DataTransferObject\Address;
use Kanvas\Guild\Customers\DataTransferObject\Contact;
use Kanvas\Guild\Customers\DataTransferObject\People as PeopleData;
use Kanvas\Guild\Leads\Actions\SyncLeadByThirdPartyCustomFieldAction;
use Kanvas\Guild\Leads\DataTransferObject\Lead as LeadData;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Leads\Models\LeadStatus;
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
        $branch = $this->company->defaultBranch;
        $firstName = (string) ($this->payload['FirstName'] ?? '');
        $lastName = (string) ($this->payload['LastName'] ?? 'Unknown');

        $contacts = [];
        if (! empty($this->payload['Email'])) {
            $contacts[] = ['value' => $this->payload['Email'], 'contacts_types_id' => 1, 'weight' => 0];
        }
        if (! empty($this->payload['Phone'])) {
            $contacts[] = ['value' => $this->payload['Phone'], 'contacts_types_id' => 2, 'weight' => 0];
        }

        $leadStatus = LeadStatus::firstOrCreate(
            ['name' => strtolower((string) ($this->payload['Status'] ?? 'new'))],
            ['is_default' => 0],
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

        return new SyncLeadByThirdPartyCustomFieldAction($leadData)->execute();
    }
}
