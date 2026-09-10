<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Odoo\Actions;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Kanvas\Connectors\Odoo\Actions\Concerns\ParsesOdooPayload;
use Kanvas\Connectors\Odoo\Enums\CustomFieldEnum;
use Kanvas\Guild\Customers\DataTransferObject\Address;
use Kanvas\Guild\Customers\DataTransferObject\Contact;
use Kanvas\Guild\Customers\DataTransferObject\People as PeopleData;
use Kanvas\Guild\Leads\Actions\SyncLeadByThirdPartyCustomFieldAction;
use Kanvas\Guild\Leads\DataTransferObject\Lead as LeadData;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Leads\Models\LeadStatus;
use Kanvas\Guild\Pipelines\Models\Pipeline;
use Spatie\LaravelData\DataCollection;

/**
 * Odoo doesn't split Lead/Opportunity into separate objects the way Salesforce does — both live
 * on `crm.lead`, distinguished by its `type` field ('lead' vs 'opportunity'). This only pulls
 * `type = 'lead'` records; the caller is responsible for filtering by type before calling this.
 * Mapping `type = 'opportunity'` to a Kanvas Deal is deliberately out of scope for this pass.
 */
class PullLeadAction
{
    use ParsesOdooPayload;

    public function __construct(
        protected AppInterface $app,
        protected CompanyInterface $company,
        protected array $payload,
        protected string $odooId,
    ) {
    }

    public function execute(): Lead
    {
        $branch = $this->company->defaultBranch ?? $this->company->user->getCurrentCompany()->branch;
        [$firstName, $lastName] = $this->splitName((string) ($this->payload['contact_name'] ?? 'Unknown'));

        $contacts = [];
        if (! empty($this->payload['email_from'])) {
            $contacts[] = ['value' => $this->payload['email_from'], 'contacts_types_id' => 1, 'weight' => 0];
        }
        if (! empty($this->payload['phone'])) {
            $contacts[] = ['value' => $this->payload['phone'], 'contacts_types_id' => 2, 'weight' => 0];
        }

        $leadStatus = LeadStatus::query()
            ->fromApp($this->app)
            ->fromCompany($this->company)
            ->notDeleted()
            ->firstOrCreate(
                ['name' => strtolower($this->relationName($this->payload['stage_id'] ?? null) ?? 'new')],
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
            title: (string) ($this->payload['name'] ?? trim($firstName . ' ' . $lastName)),
            pipeline_stage_id: $pipelineStage?->getId() ?? 0,
            people: new PeopleData(
                app: $this->app,
                branch: $branch,
                user: $this->company->user,
                firstname: $firstName,
                contacts: Contact::collect($contacts, DataCollection::class),
                address: Address::collect([], DataCollection::class),
                lastname: $lastName,
                custom_fields: [
                    CustomFieldEnum::ODOO_LEAD_ID->value => $this->odooId,
                ],
                runWorkflow: false,
            ),
            status_id: $leadStatus->getId(),
            description: $this->payload['description'] ?? null,
            custom_fields: [
                CustomFieldEnum::ODOO_LEAD_ID->value => $this->odooId,
            ],
            runWorkflow: false,
        );

        return new SyncLeadByThirdPartyCustomFieldAction($leadData)->execute();
    }
}
