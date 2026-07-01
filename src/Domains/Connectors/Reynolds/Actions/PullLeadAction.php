<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Reynolds\Actions;

use Baka\Contracts\AppInterface;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Reynolds\Entities\Customer as CustomerEntity;
use Kanvas\Connectors\Reynolds\Entities\Lead as LeadEntity;
use Kanvas\Connectors\Reynolds\Enums\CustomFieldEnum;
use Kanvas\Connectors\Reynolds\Exceptions\ReynoldsException;
use Kanvas\Guild\Customers\Models\Contact;
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
 * `vehicle_of_interest` and `trade_in`.
 *
 * People sync is delegated entirely to SyncLeadByThirdPartyCustomFieldAction —
 * we build the PeopleData via Customer::toPeopleData() once and hand it off.
 * The previous flow that also invoked PullPeopleAction up-front caused two
 * round-trips through SyncPeopleByThirdPartyCustomFieldAction (idempotent but
 * wasted DB + cache work).
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

        // Anchor the People sync so consecutive envelopes (e.g. OSL without a
        // NameRecId immediately followed by an LDU that carries the real one)
        // update the same People row instead of creating a duplicate. Lookup
        // order: existing Lead by ProspectId → People matched by email/phone.
        $existingPeopleId = $this->resolveExistingPeopleId($entity);

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
            'people' => $entity->customer->toPeopleData(
                app: $this->app,
                branch: $this->company->defaultBranch,
                user: $this->user,
                existingPeopleId: $existingPeopleId,
                // OSL envelopes don't always carry a NameRecId — fall back to a
                // ProspectId-derived synthetic so People dedup still has a stable
                // key per Reynolds prospect when the anchor lookups miss too.
                fallbackNameRecId: 'prospect:' . $entity->prospectId,
            ),
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

    private function resolveExistingPeopleId(LeadEntity $entity): ?int
    {
        // 1. Same Reynolds prospect already synced → reuse its People.
        $existingLead = LeadModel::getByCustomField(
            CustomFieldEnum::PROSPECT_ID->value,
            (string) $entity->prospectId,
            $this->company,
        );

        if ($existingLead?->people_id) {
            return (int) $existingLead->people_id;
        }

        // 2. First time we see this prospect — try to match an existing
        // customer by email or phone so we don't fork Peoples that Kanvas
        // already created from other sources (walk-ins, other CRMs, web forms).
        $email = $entity->customer?->email;
        $phone = $this->firstUsablePhone($entity->customer);

        if (empty($email) && empty($phone)) {
            return null;
        }

        $existing = PeoplesRepository::getMatchingEmailPhone(
            app: $this->app,
            company: $this->company,
            email: $email !== null ? strtolower((string) $email) : null,
            phone: $phone,
        );

        return $existing?->getId();
    }

    private function firstUsablePhone(?CustomerEntity $customer): ?string
    {
        if ($customer === null) {
            return null;
        }

        foreach ($customer->phones as $phone) {
            // Contact::cleanPhone is the canonical normalizer used by the
            // ContactObserver on write, so the value we match against in
            // peoples_contacts is comparable byte-for-byte.
            $normalized = Contact::cleanPhone((string) ($phone['num'] ?? ''));
            if ($normalized !== '') {
                return $normalized;
            }
        }

        return null;
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
        if ($name !== null) {
            $status = LeadStatus::fromApp($this->app)
                ->fromCompany($this->company)
                ->where('name', $name)
                ->first();

            if ($status !== null) {
                return $status->getId();
            }
        }

        // Reynolds Publish Lead Update does not include ProspectStatus, so most LDU
        // payloads land here. Fall back to the first available LeadStatus visible to
        // this app (including globally-seeded rows with apps_id=0) — leaving
        // leads_status_id = 0 breaks the LeadObserver which calls
        // status()->firstOrFail() on save.
        $default = LeadStatus::query()
            ->whereIn('apps_id', [0, $this->app->getId()])
            ->first();

        return $default?->getId() ?? 0;
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
