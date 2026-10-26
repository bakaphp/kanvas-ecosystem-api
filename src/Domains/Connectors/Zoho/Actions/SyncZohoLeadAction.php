<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Zoho\Actions;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Exception;
use Illuminate\Support\Facades\DB;
use Kanvas\Connectors\Zoho\Actions\Concerns\SyncsZohoPerson;
use Kanvas\Connectors\Zoho\Enums\CustomFieldEnum;
use Kanvas\Connectors\Zoho\Services\ZohoFieldMapService;
use Kanvas\Connectors\Zoho\ZohoService;
use Kanvas\Guild\Agents\Models\Agent;
use Kanvas\Guild\Customers\DataTransferObject\Address;
use Kanvas\Guild\Customers\DataTransferObject\People;
use Kanvas\Guild\Leads\Actions\CreateLeadAction;
use Kanvas\Guild\Leads\DataTransferObject\Lead as DataTransferObjectLead;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Leads\Models\LeadReceiver;
use Kanvas\Guild\Leads\Models\LeadStatus;
use Kanvas\Guild\Organizations\Actions\CreateOrganizationAction;
use Kanvas\Guild\Organizations\DataTransferObject\Organization;
use Kanvas\Guild\Pipelines\Models\Pipeline;
use Kanvas\Users\Models\UsersAssociatedApps;
use Spatie\LaravelData\DataCollection;
use Webleit\ZohoCrmApi\Models\Record;

class SyncZohoLeadAction
{
    use SyncsZohoPerson;

    public function __construct(
        protected AppInterface $app,
        protected CompanyInterface $company,
        protected LeadReceiver $receiver,
        protected string $zohoLeadId
    ) {
    }

    public function execute(?Record $zohoLead = null): ?Lead
    {
        $rejectLeadsWithoutAgents = $this->app->get('dont_allow_leads_without_agents');

        try {
            $zohoLead ??= new ZohoService($this->app, $this->company)->getLeadById($this->zohoLeadId);
        } catch (Exception $e) {
            report($e);

            return null;
        }

        $member = $zohoLead->Member;
        $agent = Agent::query()->where('member_id', $member)->fromApp($this->app)->fromCompany($this->company)->first();

        if ($rejectLeadsWithoutAgents && ! $agent) {
            return null;
        }

        $localLead = Lead::getByCustomField(
            CustomFieldEnum::ZOHO_LEAD_ID->value,
            $this->zohoLeadId,
            $this->company
        );

        if (! $localLead) {
            $table = (new Lead())->getTable();
            /** @var Lead|null $localLead */
            $localLead = Lead::query()->join(DB::connection('ecosystem')->getDatabaseName() . '.apps_custom_fields', 'apps_custom_fields.entity_id', '=', $table . '.id')
                ->where('apps_custom_fields.companies_id', $this->company->getId())
                ->where('apps_custom_fields.model_name', 'Gewaer\\Models\\Leads') //legacy
                ->where('apps_custom_fields.name', CustomFieldEnum::ZOHO_LEAD_ID->value)
                ->where('apps_custom_fields.value', $this->zohoLeadId)
                ->select($table . '.*')
                ->first();
        }

        $status = ! empty($zohoLead->Lead_Status) ? strtolower($zohoLead->Lead_Status) : '';

        $leadStatus = LeadStatus::firstOrCreate(
            [
                'name' => $status,
            ],
            [
                'is_default' => 0,
            ]
        );

        $ownerUser = null;
        if (! empty($zohoLead->Owner) && ! empty($zohoLead->Owner['email'])) {
            $ownerUser = UsersAssociatedApps::query()
                ->fromApp($this->app)
                ->where('email', $zohoLead->Owner['email'])
                ->first()?->user;
        }

        $user = $agent?->user ?? $this->company->user;
        $companyZohoMapFields = $this->company->get(CustomFieldEnum::FIELDS_MAP->value) ?? [];
        $record = $zohoLead->getData();

        if (! $localLead) {
            $pipelineStage = Pipeline::query()->fromApp($this->app)->fromCompany($this->company)->where('is_default', 1)->first()->stages()->first();
            $address = $this->personAddress($record, $companyZohoMapFields);

            /**
             * @todo assign owner and user and member # if exist
             */
            $firstName = $this->personFirstNameForCreate($record, $companyZohoMapFields) ?? '';
            $lead = new DataTransferObjectLead(
                app: $this->app,
                branch: $this->company->defaultBranch,
                user: $user ?? $this->company->user,
                title: $zohoLead->Full_Name ?? $firstName,
                pipeline_stage_id: $pipelineStage->getId(),
                people: new People(
                    app: $this->app,
                    branch: $this->company->defaultBranch,
                    user: $user ?? $this->company->user,
                    firstname: $firstName,
                    contacts: $this->personContacts($record, $companyZohoMapFields),
                    address: Address::collect($address ? [$address] : [], DataCollection::class),
                    lastname: $this->personLastName($record, $companyZohoMapFields),
                    dob: $this->personDateOfBirth($record, $companyZohoMapFields),
                ),
                leads_owner_id: $ownerUser ? $ownerUser->getId() : 0,
                status_id: $leadStatus->getId(),
                receiver_id: $this->receiver->getId(),
                custom_fields: [
                    ...$this->extractMappedCustomFields($record, $companyZohoMapFields),
                    CustomFieldEnum::ZOHO_LEAD_ID->value => $this->zohoLeadId,
                ],
                runWorkflow: false
            );

            $localLead = new CreateLeadAction($lead)->execute();
            $this->syncAddressCustomFields($localLead, $localLead->people?->getDefaultAddress());

            return $localLead;
        }

        if ($ownerUser) {
            $localLead->leads_owner_id = $ownerUser->getId();
        }

        if ($user) {
            $localLead->users_id = $user->getId();
        }

        if (! empty($zohoLead->Company)) {
            $organization = new CreateOrganizationAction(
                new Organization(
                    name: $zohoLead->Company,
                    company: $this->company,
                    app: $this->app,
                    user: $user ?? $this->company->user
                )
            )->execute();
            $localLead->organization_id = $organization->getId();
        }

        $localLead->firstname = $zohoLead->First_Name;
        $localLead->lastname = $zohoLead->Last_Name;
        $localLead->title = $zohoLead->Full_Name;
        $localLead->description = $zohoLead->Description;
        $localLead->leads_status_id = $leadStatus->getId();

        foreach ($this->extractMappedCustomFields($record, $companyZohoMapFields) as $name => $value) {
            $localLead->set($name, $value);
        }

        $localLead->disableWorkflows();
        $localLead->saveOrFail();

        $this->syncPerson($localLead->people, $record, $companyZohoMapFields);
        $this->syncAddressCustomFields($localLead, $localLead->people->getDefaultAddress());

        return $localLead;
    }

    protected function extractMappedCustomFields(array $record, array $fieldMapping): array
    {
        return ZohoFieldMapService::extract(
            $fieldMapping,
            $record,
            ZohoFieldMapService::tableColumns(new Lead())
        );
    }
}
