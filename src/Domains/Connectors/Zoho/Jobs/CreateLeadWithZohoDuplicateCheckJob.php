<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Zoho\Jobs;

use Baka\Support\Str;
use Kanvas\Connectors\Zoho\Actions\SyncZohoLeadAction;
use Kanvas\Connectors\Zoho\Client;
use Kanvas\Connectors\Zoho\Enums\CustomFieldEnum;
use Kanvas\Connectors\Zoho\Services\ZohoLeadDuplicateService;
use Kanvas\Guild\Customers\Models\Contact;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Jobs\CreateLeadsFromReceiverJob;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Leads\Models\LeadReceiver;
use Kanvas\Users\Models\Users;
use Kanvas\Users\Repositories\UsersRepository;
use Kanvas\Workflow\Attributes\WorkflowAction;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Override;
use Throwable;
use Webleit\ZohoCrmApi\Models\Record;
use Webleit\ZohoCrmApi\Modules\Records as ZohoRecordsModule;

/**
 * A receiver for forms that reach leads living only in Zoho. When the contact already has a lead in
 * Kanvas or in the Zoho Leads module, the new lead is still created, but with no source and owned by
 * the configured user instead of the rotation; the old lead is never updated. A Zoho-only match is
 * pulled into Kanvas first, so it is tracked from then on like any other synced lead. Without
 * `duplicate_zoho_owner_id` in the webhook configuration it behaves exactly like the standard receiver.
 */
#[WorkflowAction(
    name: 'Zoho Lead Receiver With Duplicate Check',
    description: 'Creates a lead from a form, first checking Kanvas and the Zoho Leads module for an existing '
        . 'lead with the same email or phone. A duplicate is still created, with no lead source and owned by '
        . 'the configured user instead of the rotation. Only useful if this company runs Zoho.',
    integration: IntegrationsEnum::ZOHO,
)]
class CreateLeadWithZohoDuplicateCheckJob extends CreateLeadsFromReceiverJob
{
    public const string STATUS_MATCHED = 'matched';
    public const string STATUS_CLEAR = 'clear';
    public const string STATUS_FAILED = 'failed';

    /**
     * @var array{status: string, kanvas_lead_ids: int[], zoho_ids: string[], pulled_lead_ids: int[]}|null
     */
    private ?array $duplicateCheck = null;

    #[Override]
    protected function buildPayload(LeadReceiver $leadReceiver): array
    {
        $payload = parent::buildPayload($leadReceiver);
        $zohoOwnerId = $this->duplicateZohoOwnerId();

        if ($zohoOwnerId === null) {
            return $payload;
        }

        [$email, $phones] = $this->contactsFromPayload($payload);
        $this->duplicateCheck = $this->checkForDuplicates(
            $leadReceiver,
            $email,
            $phones
        );

        if (! $this->isDuplicate()) {
            return $payload;
        }

        $payload['source_id'] = 0;
        $payload['description'] = trim(($payload['description'] ?? '') . "\n\nPossible duplicate of " . implode(', ', $this->duplicateRefs()));
        $payload['custom_fields'] = $this->withCustomFields(
            $payload['custom_fields'] ?? [],
            [
                CustomFieldEnum::ZOHO_DUPLICATE_OF->value => implode(',', $this->duplicateRefs()),
                CustomFieldEnum::ZOHO_DUPLICATE_OWNER_ID->value => $zohoOwnerId,
            ]
        );

        return $payload;
    }

    #[Override]
    protected function resolveOwner(LeadReceiver $leadReceiver, array $payload): array
    {
        if (! $this->isDuplicate()) {
            return parent::resolveOwner($leadReceiver, $payload);
        }

        $owner = $this->duplicateOwner();

        if ($owner !== null) {
            $payload['leads_owner_id'] = $owner->getId();
        }

        return [$owner, $payload];
    }

    #[Override]
    protected function buildResponse(
        LeadReceiver $leadReceiver,
        Lead $lead,
        array $sentEmail,
        bool $showCustomFields,
    ): array {
        return [
            ...parent::buildResponse(
                $leadReceiver,
                $lead,
                $sentEmail,
                $showCustomFields
            ),
            'duplicate_check' => $this->duplicateCheck,
        ];
    }

    protected function zohoLeadsModule(): ZohoRecordsModule
    {
        return Client::getInstance($this->receiver->app, $this->receiver->company)->leads;
    }

    /**
     * Kanvas first: it is a local query and catches leads whose Zoho push is still queued. Zoho is only
     * asked when Kanvas has nothing.
     *
     * @param string[] $phones
     * @return array{status: string, kanvas_lead_ids: int[], zoho_ids: string[], pulled_lead_ids: int[]}
     */
    private function checkForDuplicates(LeadReceiver $leadReceiver, ?string $email, array $phones): array
    {
        $kanvasLeadIds = $this->findKanvasLeadIds($email, $phones);

        if ($kanvasLeadIds !== []) {
            return $this->checkResult(self::STATUS_MATCHED, kanvasLeadIds: $kanvasLeadIds);
        }

        try {
            $zohoLeads = new ZohoLeadDuplicateService($this->zohoLeadsModule())->findLeads($email, $phones);
        } catch (Throwable $e) {
            report($e);

            return $this->checkResult(self::STATUS_FAILED);
        }

        if ($zohoLeads === []) {
            return $this->checkResult(self::STATUS_CLEAR);
        }

        return $this->checkResult(
            self::STATUS_MATCHED,
            zohoIds: array_map('strval', array_keys($zohoLeads)),
            pulledLeadIds: $this->pullZohoLeads($leadReceiver, $zohoLeads)
        );
    }

    /**
     * A lead that lived only in Zoho is brought into Kanvas, so Zoho's update webhooks find it by
     * ZOHO_LEAD_ID from now on and the next submission matches it locally. The pull runs without
     * workflows, so the old Zoho lead is never written back to. A failed pull is reported and skipped:
     * the new lead is still routed as a duplicate.
     *
     * @param array<string, Record> $zohoLeads
     * @return int[]
     */
    private function pullZohoLeads(LeadReceiver $leadReceiver, array $zohoLeads): array
    {
        $pulled = [];

        foreach ($zohoLeads as $zohoLeadId => $record) {
            try {
                $lead = new SyncZohoLeadAction(
                    $this->receiver->app,
                    $this->receiver->company,
                    $leadReceiver,
                    (string) $zohoLeadId
                )->execute($record);
            } catch (Throwable $e) {
                report($e);

                continue;
            }

            if ($lead !== null) {
                $pulled[] = $lead->getId();
            }
        }

        return $pulled;
    }

    /**
     * @param string[] $phones
     * @return int[]
     */
    private function findKanvasLeadIds(?string $email, array $phones): array
    {
        $company = $this->receiver->company;
        $app = $this->receiver->app;
        $people = People::getAllByPhoneOrEmail(
            null,
            $email,
            $company,
            $app
        );

        foreach ($phones as $phone) {
            $people = $people->merge(People::getAllByPhoneOrEmail(
                $phone,
                null,
                $company,
                $app
            ));
        }

        if ($people->isEmpty()) {
            return [];
        }

        return Lead::query()
            ->fromApp($app)
            ->fromCompany($company)
            ->whereIn('people_id', $people->pluck('id')->unique()->all())
            ->notDeleted()
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * @return array{0: ?string, 1: string[]}
     */
    private function contactsFromPayload(array $payload): array
    {
        $email = null;
        $phones = [];

        foreach ($payload['people']['contacts'] ?? [] as $contact) {
            $value = Str::trimToNull((string) ($contact['value'] ?? ''));
            $type = (int) ($contact['contacts_types_id'] ?? 0);

            if ($value === null) {
                continue;
            }

            if ($email === null && Contact::isEmailType($type)) {
                $email = $value;
            } elseif (Contact::isPhoneType($type)) {
                $phones[] = $value;
            }
        }

        return [$email, array_values(array_unique($phones))];
    }

    /**
     * A misconfigured user id must not lose the lead: it falls back to the receiver's user.
     */
    private function duplicateOwner(): ?Users
    {
        $userId = (int) ($this->receiver->configuration['duplicate_owner_users_id'] ?? 0);

        if ($userId === 0) {
            return null;
        }

        try {
            return UsersRepository::getUserOfCompanyById($this->receiver->company, $userId);
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }

    private function duplicateZohoOwnerId(): ?string
    {
        return Str::trimToNull((string) ($this->receiver->configuration['duplicate_zoho_owner_id'] ?? ''));
    }

    private function isDuplicate(): bool
    {
        return ($this->duplicateCheck['status'] ?? null) === self::STATUS_MATCHED;
    }

    /**
     * @return string[]
     */
    private function duplicateRefs(): array
    {
        return [
            ...array_map(fn (int $id) => 'kanvas:' . $id, $this->duplicateCheck['kanvas_lead_ids']),
            ...array_map(fn (string $id) => 'zoho:' . $id, $this->duplicateCheck['zoho_ids']),
        ];
    }

    /**
     * The payload's custom fields arrive either as a key => value map or as a list of `{name, data}` /
     * `{key, value}` entries. `setCustomFields()` reads the entry keys off the first item, so the extra
     * fields have to copy that shape or they are dropped.
     */
    private function withCustomFields(array $customFields, array $extra): array
    {
        if ($customFields === [] || ! array_is_list($customFields)) {
            return [...$customFields, ...$extra];
        }

        $keyField = array_key_exists('name', $customFields[0]) ? 'name' : 'key';
        $valueField = array_key_exists('data', $customFields[0]) ? 'data' : 'value';

        foreach ($extra as $name => $data) {
            $customFields[] = [$keyField => $name, $valueField => $data];
        }

        return $customFields;
    }

    /**
     * @param int[] $kanvasLeadIds
     * @param string[] $zohoIds
     * @param int[] $pulledLeadIds
     */
    private function checkResult(
        string $status,
        array $kanvasLeadIds = [],
        array $zohoIds = [],
        array $pulledLeadIds = []
    ): array {
        return [
            'status' => $status,
            'kanvas_lead_ids' => $kanvasLeadIds,
            'zoho_ids' => $zohoIds,
            'pulled_lead_ids' => $pulledLeadIds,
        ];
    }
}
