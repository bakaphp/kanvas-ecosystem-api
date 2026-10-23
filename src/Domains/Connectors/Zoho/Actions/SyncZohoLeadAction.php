<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Zoho\Actions;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Support\Str;
use Carbon\Carbon;
use Exception;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Kanvas\Connectors\Zoho\Enums\CustomFieldEnum;
use Kanvas\Connectors\Zoho\ZohoService;
use Kanvas\Guild\Agents\Models\Agent;
use Kanvas\Guild\Customers\DataTransferObject\Address;
use Kanvas\Guild\Customers\DataTransferObject\Contact;
use Kanvas\Guild\Customers\DataTransferObject\People;
use Kanvas\Guild\Customers\Models\People as PeopleModel;
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
use Throwable;
use Webleit\ZohoCrmApi\Models\Record;

class SyncZohoLeadAction
{
    /**
     * Zoho field each person attribute is read from when the company's ZOHO_FIELDS_MAP doesn't map it.
     */
    protected const array DEFAULT_PERSON_FIELDS = [
        'street' => 'Street',
        'city' => 'City',
        'state' => 'State',
        'zip' => 'Zip_Code',
        'Date_of_Birth' => 'Date_of_Birth',
    ];

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
        $address = $this->buildAddress($zohoLead, $companyZohoMapFields);
        $dob = $this->parseDateOfBirth($zohoLead, $companyZohoMapFields);

        if (! $localLead) {
            $pipelineStage = Pipeline::query()->fromApp($this->app)->fromCompany($this->company)->where('is_default', 1)->first()->stages()->first();

            $contact = [];

            if (! empty($zohoLead->Email)) {
                $contact[] = [
                    'value' => $zohoLead->Email,
                    'contacts_types_id' => 1,
                    'weight' => 0,
                ];
            }

            if (! empty($zohoLead->Phone)) {
                $contact[] = [
                    'value' => $zohoLead->Phone,
                    'contacts_types_id' => 2,
                    'weight' => 0,
                ];
            }

            /**
             * @todo assign owner and user and member # if exist
             */
            $firstName = $zohoLead->First_Name
                        ?? $zohoLead->Full_Name
                        ?? (! empty($zohoLead->Email) ? Str::before($zohoLead->Email, '@') : '');
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
                    contacts: Contact::collect($contact, DataCollection::class),
                    address: Address::collect($address ? [$address] : [], DataCollection::class),
                    lastname: $zohoLead->Last_Name,
                    dob: $dob,
                ),
                leads_owner_id: $ownerUser ? $ownerUser->getId() : 0,
                status_id: $leadStatus->getId(),
                receiver_id: $this->receiver->getId(),
                custom_fields: [
                    ...$this->extractMappedCustomFields($zohoLead, $companyZohoMapFields),
                    CustomFieldEnum::ZOHO_LEAD_ID->value => $this->zohoLeadId,
                ],
                runWorkflow: false
            );

            return new CreateLeadAction($lead)->execute();
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

        $localLead->people->firstname = $zohoLead->First_Name ?? $zohoLead->Full_Name;
        $localLead->people->lastname = $zohoLead->Last_Name;
        $localLead->firstname = $zohoLead->First_Name;
        $localLead->lastname = $zohoLead->Last_Name;
        $localLead->title = $zohoLead->Full_Name;
        $localLead->description = $zohoLead->Description;
        $localLead->leads_status_id = $leadStatus->getId();

        foreach ($this->extractMappedCustomFields($zohoLead, $companyZohoMapFields) as $name => $value) {
            $localLead->set($name, $value);
        }

        $localLead->disableWorkflows();
        $localLead->saveOrFail();

        $this->syncPeople($localLead->people, $address, $dob);

        return $localLead;
    }

    protected function syncPeople(PeopleModel $people, ?Address $address, ?Carbon $dob): void
    {
        if ($dob !== null) {
            $people->dob = $dob->format('Y-m-d');
        }

        $people->disableWorkflows();
        $people->saveOrFail();

        if ($address !== null) {
            $people->replaceDefaultAddress($address);
        }
    }

    protected function buildAddress(Record $zohoLead, array $fieldMapping): ?Address
    {
        $street = Str::trimToNull((string) $this->getMappedZohoValue($zohoLead, $fieldMapping, 'street'));

        if ($street === null) {
            return null;
        }

        return Address::fromArray([
            'address' => $street,
            'city' => Str::trimToNull((string) $this->getMappedZohoValue($zohoLead, $fieldMapping, 'city')),
            'state' => Str::trimToNull((string) $this->getMappedZohoValue($zohoLead, $fieldMapping, 'state')),
            'zip' => Str::trimToNull((string) $this->getMappedZohoValue($zohoLead, $fieldMapping, 'zip')),
            'is_default' => true,
        ]);
    }

    protected function parseDateOfBirth(Record $zohoLead, array $fieldMapping): ?Carbon
    {
        $dob = Str::trimToNull((string) $this->getMappedZohoValue($zohoLead, $fieldMapping, 'Date_of_Birth'));

        if ($dob === null) {
            return null;
        }

        try {
            return Carbon::parse($dob)->startOfDay();
        } catch (Throwable) {
            return null;
        }
    }

    protected function getMappedZohoValue(Record $zohoLead, array $fieldMapping, string $localFieldName): mixed
    {
        $zohoFieldName = $this->resolveZohoFieldName(
            $fieldMapping[$localFieldName] ?? null,
            self::DEFAULT_PERSON_FIELDS[$localFieldName]
        );

        return $zohoLead->$zohoFieldName;
    }

    protected function resolveZohoFieldName(mixed $config, string $fallback): string
    {
        $name = is_array($config) ? ($config['name'] ?? null) : $config;

        return is_string($name) && $name !== '' ? $name : $fallback;
    }

    protected function extractMappedCustomFields(Record $zohoLead, array $fieldMapping): array
    {
        $leadTableFields = $this->getLeadTableFields();
        $customFields = [];

        foreach ($fieldMapping as $localFieldName => $zohoFieldConfig) {
            if (! is_string($localFieldName) || in_array($localFieldName, $leadTableFields)) {
                continue;
            }

            $zohoFieldName = $this->resolveZohoFieldName($zohoFieldConfig, $localFieldName);

            if (empty($zohoLead->$zohoFieldName)) {
                continue;
            }

            $value = $zohoLead->$zohoFieldName;

            if (is_array($zohoFieldConfig) && ($zohoFieldConfig['type'] ?? null) === 'date') {
                $timestamp = strtotime((string) $value);
                if ($timestamp !== false) {
                    $value = date('Y-m-d', $timestamp);
                }
            }

            $customFields[$localFieldName] = $value;
        }

        return $customFields;
    }

    protected function getLeadTableFields(): array
    {
        $leadModel = new Lead();
        $tableName = $leadModel->getTable();
        $connection = $leadModel->getConnection();
        $connectionName = $connection->getName();

        // Create a unique cache key that includes the connection and table name
        $cacheKey = "lead_table_fields_{$connectionName}_{$tableName}";

        // Cache for 1 hour (3600 seconds)
        return Cache::remember($cacheKey, 3600, function () use ($connectionName, $tableName) {
            return Schema::connection($connectionName)->getColumnListing($tableName);
        });
    }
}
