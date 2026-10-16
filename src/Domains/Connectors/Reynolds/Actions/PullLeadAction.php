<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Reynolds\Actions;

use Baka\Contracts\AppInterface;
use Baka\Support\Str;
use Illuminate\Database\Eloquent\Collection;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Reynolds\Entities\Customer as CustomerEntity;
use Kanvas\Connectors\Reynolds\Entities\Lead as LeadEntity;
use Kanvas\Connectors\Reynolds\Enums\CustomFieldEnum;
use Kanvas\Connectors\Reynolds\Exceptions\ReynoldsException;
use Kanvas\Connectors\Reynolds\Services\SalespersonResolver;
use Kanvas\Guild\Customers\Models\Contact;
use Kanvas\Guild\Customers\Models\People as PeopleModel;
use Kanvas\Guild\Customers\Repositories\PeoplesRepository;
use Kanvas\Guild\Leads\Actions\SyncLeadByThirdPartyCustomFieldAction;
use Kanvas\Guild\Leads\DataTransferObject\Lead as LeadData;
use Kanvas\Guild\Leads\Models\Lead as LeadModel;
use Kanvas\Guild\Leads\Models\LeadSource;
use Kanvas\Guild\Leads\Models\LeadStatus;
use Kanvas\Guild\Leads\Models\LeadType;
use Kanvas\Users\Models\Users;

/**
 * Process an inbound Reynolds Publish Lead Update payload.
 *
 * Receives the parsed array of a `rey_SalesAssistCRMPublishLeadUpdate.Record` element
 * and creates/updates the corresponding Lead + People in Kanvas. Vehicle of interest
 * and trade-in are stored as Lead custom fields under the Kanvas-standard keys
 * `vehicle_of_interest` and `vehicle_trade_id` (the VinSolution TradeIn shape).
 *
 * People sync is delegated entirely to SyncLeadByThirdPartyCustomFieldAction —
 * we build the PeopleData via Customer::toPeopleData() once and hand it off.
 * The previous flow that also invoked PullPeopleAction up-front caused two
 * round-trips through SyncPeopleByThirdPartyCustomFieldAction (idempotent but
 * wasted DB + cache work).
 */
class PullLeadAction
{
    private const string SYNTHETIC_NAME_REC_ID_PREFIX = 'prospect:';

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

        // R&R LDU envelopes only carry the fields that changed — the second
        // envelope for a prospect can arrive with just <IsAiGenerated> under
        // <Prospect> and nothing else, so we have to preserve whatever the
        // existing Lead already has for anything the envelope doesn't repeat.
        $existingLead = $this->findExistingLead((string) $entity->prospectId);

        $existingPeople = $this->resolveExistingPeople($entity, $existingLead);

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
            $data = $entity->potentialTrade;
            $data['firstName'] = $entity->customer->firstName;
            $data['lastName'] = $entity->customer->lastName;
            $data['fullName'] = $entity->customer->firstName . ' ' . $entity->customer->lastName;
            $customFields[CustomFieldEnum::TRADE_IN->value] = $this->buildTradeInCustomField($data);
        }

        $leadData = LeadData::from([
            'app' => $this->app,
            'branch' => $this->company->defaultBranch,
            'user' => $this->user,
            // Keep the title the dealer/agent might have hand-edited when we're
            // updating an existing lead; only compute a fresh one on first sync.
            'title' => $existingLead?->title ?? $this->buildTitle($entity),
            'pipeline_stage_id' => 0,
            'people' => $entity->customer->toPeopleData(
                app: $this->app,
                branch: $this->company->defaultBranch,
                user: $this->user,
                existingPeopleId: $existingPeople?->getId(),
                fallbackNameRecId: $this->resolveFallbackNameRecId($entity, $existingPeople),
            ),
            // Every scalar below preserves the existing Lead's value when the
            // envelope doesn't carry a fresh one — R&R only ships changed
            // fields on LDU, so overwriting with null would wipe what OSL set.
            'leads_owner_id' => $this->resolveOwnerId($entity)
                ?? $existingLead?->leads_owner_id
                ?? $this->user->getId(),
            'type_id' => $this->resolveTypeId(
                $entity->prospectType,
                $existingLead?->leads_types_id,
            ),
            // Real R&R envelopes carry <ProspectStatusType> (e.g. "Open",
            // "Closed", "Sold"), and the LDU spec's <ProspectStatus> field
            // hardly ever shows up in production. Prefer the status type,
            // fall back to prospectStatus for spec-shaped payloads.
            'status_id' => $this->resolveStatusId(
                $entity->prospectStatusType ?? $entity->prospectStatus,
                $existingLead?->leads_status_id,
            ),
            'source_id' => $this->resolveSourceId(
                $entity->providerName,
                $existingLead?->leads_sources_id,
            ),
            'receiver_id' => 0,
            'description' => $entity->prospectNote ?? $existingLead?->description,
            'custom_fields' => $customFields,
        ]);

        return new SyncLeadByThirdPartyCustomFieldAction($leadData)->execute();
    }

    private function findExistingLead(string $prospectId): ?LeadModel
    {
        if ($prospectId === '') {
            return null;
        }

        /** @var LeadModel|null $lead */
        $lead = LeadModel::getByCustomField(
            CustomFieldEnum::PROSPECT_ID->value,
            $prospectId,
            $this->company,
        );

        return $lead;
    }

    /**
     * With a NameRecId the People identity is the REYNOLDS_NAME_REC_ID custom
     * field alone — an unknown id becomes a new People even if it shares an
     * email or phone with someone, so two customers can share a contact and
     * stay two People. Only an envelope without NameRecId falls back to the
     * prospect's current People and then to any shared contact.
     */
    private function resolveExistingPeople(LeadEntity $entity, ?LeadModel $existingLead): ?PeopleModel
    {
        $nameRecId = $this->realNameRecId($entity);

        if ($nameRecId !== null) {
            /** @var PeopleModel|null $people */
            $people = PeopleModel::getByCustomField(
                CustomFieldEnum::NAME_REC_ID->value,
                $nameRecId,
                $this->company,
            );

            return $people ?? $this->prospectPeopleWithPlaceholderIdentity($entity, $existingLead);
        }

        return $existingLead?->people
            ?? $this->findPeopleByContacts($entity->customer)->first();
    }

    /**
     * An OSL without NameRecId (synthetic `prospect:` key) or an outbound push
     * (PushLeadAction stamps the bare ProspectId because the ISL response has
     * no NameRecId) leaves the prospect's People with a placeholder identity
     * that the first LDU carrying the real id must reclaim — but a People
     * already owned by a different real NameRecId is never reused.
     */
    private function prospectPeopleWithPlaceholderIdentity(LeadEntity $entity, ?LeadModel $existingLead): ?PeopleModel
    {
        $people = $existingLead?->people;
        if (! $people instanceof PeopleModel) {
            return null;
        }

        $current = Str::trimToNull((string) $people->get(CustomFieldEnum::NAME_REC_ID->value));

        $isPlaceholder = $current === null
            || $current === (string) $entity->prospectId
            || str_starts_with($current, self::SYNTHETIC_NAME_REC_ID_PREFIX);

        return $isPlaceholder ? $people : null;
    }

    /**
     * @return Collection<int, PeopleModel>
     */
    private function findPeopleByContacts(?CustomerEntity $customer): Collection
    {
        if ($customer === null) {
            return new Collection();
        }

        $emails = array_filter([$customer->email]);
        $phones = array_filter(array_map(
            fn (array $phone) => Contact::cleanPhone((string) ($phone['num'] ?? '')),
            $customer->phones
        ));

        if (empty($emails) && empty($phones)) {
            return new Collection();
        }

        return PeoplesRepository::getByAnyContact(
            app: $this->app,
            company: $this->company,
            emails: $emails,
            phones: $phones,
        )->get();
    }

    /**
     * When the envelope carries no NameRecId, keep whatever identifier the
     * prospect's People already has so the sync updates it instead of stamping
     * a synthetic key over a real one; otherwise derive a stable per-prospect
     * synthetic so dedup still has a key.
     */
    private function resolveFallbackNameRecId(LeadEntity $entity, ?PeopleModel $existingPeople): string
    {
        $current = $existingPeople !== null
            ? Str::trimToNull((string) $existingPeople->get(CustomFieldEnum::NAME_REC_ID->value))
            : null;

        return $current ?? self::SYNTHETIC_NAME_REC_ID_PREFIX . $entity->prospectId;
    }

    private function realNameRecId(LeadEntity $entity): ?string
    {
        return Str::trimToNull($entity->customer?->nameRecId);
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

    private function resolveOwnerId(LeadEntity $entity): ?int
    {
        return SalespersonResolver::resolveUserId($entity->primarySalesPerson, $this->company);
    }

    /**
     * Map R&R's five trade fields into the VinSolution TradeIn shape so the
     * shared VehicleTradeInTool reads it; the rest default to VinSolution's
     * neutral values.
     */
    private function buildTradeInCustomField(array $trade): array
    {
        return [
            'vin' => $trade['vin'] ?? null,
            'year' => isset($trade['year']) ? (string) $trade['year'] : null,
            'make' => $trade['make'] ?? null,
            'model' => $trade['model'] ?? null,
            'trim' => null,
            'exteriorColor' => null,
            'interiorColor' => null,
            'bodyStyle' => null,
            'engineName' => null,
            'transmission' => null,
            'driveTrain' => null,
            'doors' => null,
            'mileage' => isset($trade['odometer']) ? (int) str_replace(',', '', (string) $trade['odometer']) : 0,
            'value' => 0.0,
            'condition' => 'UNKNOWN',
            'description' => null,
            'payOff' => 0.0,
            'firstName' => $trade['firstName'],
            'lastName' => $trade['lastName'],
            'fullName' => $trade['firstName'] . ' ' . $trade['lastName'],
        ];
    }

    private function resolveTypeId(?string $name, ?int $currentId = null): int
    {
        if ($name === null) {
            return (int) ($currentId ?? 0);
        }

        $type = LeadType::firstOrCreate(
            [
                'apps_id' => $this->app->getId(),
                'companies_id' => $this->company->getId(),
                'name' => $name,
            ],
            [
                'description' => "Reynolds ProspectType: {$name}",
                'is_active' => 1,
            ],
        );

        return $type->getId();
    }

    private function resolveStatusId(?string $name, ?int $currentId = null): int
    {
        // Reynolds Publish Lead Update does not include ProspectStatus, so most LDU
        // payloads land here. Prefer the existing Lead's status (LDU is only
        // shipping deltas), then fall back to the first available LeadStatus
        // visible to this app (including globally-seeded rows with apps_id=0) —
        // leaving leads_status_id = 0 breaks the LeadObserver which calls
        // status()->firstOrFail() on save.
        if ($name === null) {
            if ($currentId !== null) {
                return $currentId;
            }

            $default = LeadStatus::query()
                ->whereIn('apps_id', [0, $this->app->getId()])
                ->first();

            return $default?->getId() ?? 0;
        }
        $name = $name == 'Open' ? 'Active' : $name;
        $status = LeadStatus::firstOrCreate(
            [
                'apps_id' => $this->app->getId(),
                'companies_id' => $this->company->getId(),
                'name' => $name,
            ],
            [
                'is_default' => 0,
            ],
        );

        return $status->getId();
    }

    private function resolveSourceId(?string $name, ?int $currentId = null): int
    {
        if ($name === null) {
            return (int) ($currentId ?? 0);
        }

        $source = LeadSource::firstOrCreate(
            [
                'apps_id' => $this->app->getId(),
                'companies_id' => $this->company->getId(),
                'name' => $name,
            ],
            [
                'description' => "Reynolds ProviderName: {$name}",
                'is_active' => 1,
            ],
        );

        return $source->getId();
    }
}
