<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Reynolds\Actions;

use Baka\Contracts\AppInterface;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Reynolds\Entities\Lead as LeadEntity;
use Kanvas\Connectors\Reynolds\Enums\CustomFieldEnum;
use Kanvas\Connectors\Reynolds\Exceptions\ReynoldsException;
use Kanvas\Guild\Customers\DataTransferObject\Address;
use Kanvas\Guild\Customers\DataTransferObject\Contact;
use Kanvas\Guild\Customers\DataTransferObject\People as PeopleData;
use Kanvas\Guild\Customers\Enums\ContactTypeEnum;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Actions\SyncLeadByThirdPartyCustomFieldAction;
use Kanvas\Guild\Leads\DataTransferObject\Lead as LeadData;
use Kanvas\Guild\Leads\Models\Lead as LeadModel;
use Kanvas\Guild\Leads\Models\LeadSource;
use Kanvas\Guild\Leads\Models\LeadStatus;
use Kanvas\Guild\Leads\Models\LeadType;
use Kanvas\Locations\Models\Countries;
use Kanvas\Users\Models\Users;

/**
 * Process an inbound Reynolds Publish Lead Update payload.
 *
 * Receives the parsed array of a `rey_SalesAssistCRMPublishLeadUpdate.Record` element
 * and creates/updates the corresponding Lead + People in Kanvas. Vehicle of interest
 * and trade-in are stored as Lead custom fields (matching the Elead/VinSolution pattern).
 */
class PullLeadAction
{
    public function __construct(
        protected AppInterface $app,
        protected Companies $company,
        protected Users $user
    ) {
    }

    public function execute(array $record): LeadModel
    {
        $entity = LeadEntity::fromRecord($record);

        if ($entity->prospectId === null || $entity->customer === null) {
            throw new ReynoldsException('Lead Update payload missing ProspectId or Customer');
        }

        $people = new PullPeopleAction($this->app, $this->company, $this->user)->execute($record);

        $customFields = [
            CustomFieldEnum::PROSPECT_ID->value => $entity->prospectId,
        ];

        if ($entity->prospectType !== null) {
            $customFields[CustomFieldEnum::PROSPECT_TYPE->value] = $entity->prospectType;
        }

        if ($entity->prospectStatus !== null) {
            $customFields[CustomFieldEnum::PROSPECT_STATUS->value] = $entity->prospectStatus;
        }

        if ($entity->prospectStatusType !== null) {
            $customFields[CustomFieldEnum::PROSPECT_STATUS_TYPE->value] = $entity->prospectStatusType;
        }

        if ($entity->providerName !== null) {
            $customFields[CustomFieldEnum::PROVIDER_NAME->value] = $entity->providerName;
        }

        if ($entity->providerService !== null) {
            $customFields[CustomFieldEnum::PROVIDER_SERVICE->value] = $entity->providerService;
        }

        if ($entity->isAiGenerated !== null) {
            $customFields[CustomFieldEnum::IS_AI_GENERATED->value] = $entity->isAiGenerated;
        }

        if ($entity->isCiLead !== null) {
            $customFields[CustomFieldEnum::IS_CI_LEAD->value] = $entity->isCiLead;
        }

        if ($entity->prospectNote !== null) {
            $customFields[CustomFieldEnum::PROSPECT_NOTE->value] = $entity->prospectNote;
        }

        if (! empty($entity->desiredVehicle)) {
            $customFields[CustomFieldEnum::VEHICLE_OF_INTEREST->value] = $entity->desiredVehicle;
        }

        if (! empty($entity->potentialTrade)) {
            $customFields[CustomFieldEnum::TRADE_IN->value] = $entity->potentialTrade;
        }

        $leadData = LeadData::from([
            'app' => $this->app,
            'branch' => $this->company->defaultBranch,
            'user' => $this->user,
            'title' => $this->buildTitle($entity),
            'pipeline_stage_id' => 0,
            'people' => $this->buildPeopleDtoForLead($people, $entity),
            'leads_owner_id' => $this->resolveOwnerId($entity) ?? $this->user->getId(),
            'type_id' => $this->resolveTypeId($entity->prospectType),
            'status_id' => $this->resolveStatusId($entity->prospectStatus),
            'source_id' => $this->resolveSourceId($entity->providerName),
            'receiver_id' => 0,
            'description' => $entity->prospectNote,
            'custom_fields' => $customFields,
        ]);

        return new SyncLeadByThirdPartyCustomFieldAction($leadData)->execute();
    }

    private function buildTitle(LeadEntity $entity): string
    {
        $name = $entity->customer?->displayName() ?? 'Reynolds Lead';
        $vehicle = $entity->desiredVehicle;
        if (! empty($vehicle['year']) || ! empty($vehicle['make']) || ! empty($vehicle['model'])) {
            $name .= ' - ' . trim(($vehicle['year'] ?? '') . ' ' . ($vehicle['make'] ?? '') . ' ' . ($vehicle['model'] ?? ''));
        }

        return $name;
    }

    /**
     * Construct the People DTO embedded in the Lead DTO. The People row itself is
     * already created/updated by PullPeopleAction — we pass the same data so the
     * SyncLeadByThirdPartyCustomFieldAction wiring stays happy.
     */
    private function buildPeopleDtoForLead(People $people, LeadEntity $entity): PeopleData
    {
        $customer = $entity->customer;
        $country = Countries::getByCode($customer?->address['Country'] ?? 'US') ?? Countries::getByCode('US');

        $contacts = [];
        if ($customer?->email !== null) {
            $contacts[] = Contact::from([
                'value' => $customer->email,
                'contacts_types_id' => ContactTypeEnum::EMAIL->value,
                'weight' => 0,
            ]);
        }

        foreach ($customer?->phones ?? [] as $phone) {
            $num = preg_replace('/\D+/', '', (string) ($phone['num'] ?? ''));
            if ($num === '') {
                continue;
            }

            $contacts[] = Contact::from([
                'value' => $num,
                'contacts_types_id' => ($phone['type'] ?? '') === 'C'
                    ? ContactTypeEnum::CELLPHONE->value
                    : ContactTypeEnum::PHONE->value,
                'weight' => ($phone['type'] ?? '') === 'C' ? 100 : 0,
            ]);
        }

        $addresses = [];
        if (! empty($customer?->address)) {
            $addresses[] = Address::from([
                'address' => $customer->address['Addr1'] ?? '',
                'address_2' => $customer->address['Addr2'] ?? '',
                'city' => $customer->address['City'] ?? '',
                'state' => $customer->address['State'] ?? '',
                'zip' => $customer->address['Zip'] ?? '',
                'county' => $customer->address['County'] ?? '',
                'countries_id' => $country?->getId(),
            ]);
        }

        return PeopleData::from([
            'id' => $people->getId(),
            'app' => $this->app,
            'branch' => $this->company->defaultBranch,
            'user' => $this->user,
            'firstname' => $people->firstname,
            'lastname' => $people->lastname,
            'middlename' => $people->middlename,
            'contacts' => $contacts,
            'address' => $addresses,
            'custom_fields' => [
                CustomFieldEnum::NAME_REC_ID->value => $customer?->nameRecId,
            ],
        ]);
    }

    private function resolveOwnerId(LeadEntity $entity): ?int
    {
        if ($entity->primarySalesPerson === null) {
            return null;
        }

        $parts = explode(' ', trim($entity->primarySalesPerson), 2);
        $firstname = $parts[0] ?? null;
        $lastname = $parts[1] ?? null;

        if (! $firstname || ! $lastname) {
            return null;
        }

        $user = Users::query()
            ->where('firstname', $firstname)
            ->where('lastname', $lastname)
            ->first();

        return $user?->getId();
    }

    private function resolveTypeId(?string $name): int
    {
        if ($name === null) {
            return 0;
        }

        $type = LeadType::fromApp($this->app)
            ->fromCompany($this->company)
            ->where('name', $name)
            ->first();

        return $type?->getId() ?? 0;
    }

    private function resolveStatusId(?string $name): int
    {
        if ($name === null) {
            return 0;
        }

        $status = LeadStatus::fromApp($this->app)
            ->fromCompany($this->company)
            ->where('name', $name)
            ->first();

        return $status?->getId() ?? 0;
    }

    private function resolveSourceId(?string $name): int
    {
        if ($name === null) {
            return 0;
        }

        $source = LeadSource::fromApp($this->app)
            ->fromCompany($this->company)
            ->where('name', $name)
            ->first();

        return $source?->getId() ?? 0;
    }
}
