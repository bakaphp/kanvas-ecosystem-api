<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Zoho\Jobs;

use Baka\Support\Str;
use Kanvas\Connectors\Zoho\Actions\PullConvertedLeadDealAction;
use Kanvas\Connectors\Zoho\Actions\SyncZohoLeadAction;
use Kanvas\Connectors\Zoho\Client;
use Kanvas\Connectors\Zoho\Enums\CustomFieldEnum;
use Kanvas\Connectors\Zoho\Services\ZohoLeadDuplicateService;
use Kanvas\Connectors\Zoho\ZohoService;
use Kanvas\Guild\Customers\Enums\ContactTypeEnum;
use Kanvas\Guild\Customers\Models\Contact;
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
 * Kanvas or in the Zoho Leads module, no lead is created: the most recent existing one is reassigned
 * to the configured owner on both sides, keeping its source and appending the form origin to its description.
 * A Zoho-only match is pulled into Kanvas first so later submissions find it locally. When that
 * match was already converted in Zoho it is a deal now: the lead and its deal are pulled, and
 * nothing is reassigned, because the converted lead is read-only and the deal has its own owner.
 * Without `duplicate_zoho_owner_id` in the webhook configuration it is the standard receiver.
 */
#[WorkflowAction(
    name: 'Zoho Lead Receiver With Duplicate Check',
    description: 'Creates a lead from a form, first checking Kanvas and the Zoho Leads module for an existing '
        . 'lead with the same email or phone. On a match no lead is created: the existing lead is reassigned to '
        . 'the configured owner, keeps its lead source, and records the form origin in its description. '
        . 'A match Zoho already converted to a deal is pulled in with its deal and left with its owner. '
        . 'Only useful if this company runs Zoho.',
    integration: IntegrationsEnum::ZOHO,
)]
class CreateLeadWithZohoDuplicateCheckJob extends CreateLeadsFromReceiverJob
{
    public const string STATUS_MATCHED = 'matched';
    public const string STATUS_CLEAR = 'clear';
    public const string STATUS_FAILED = 'failed';

    /**
     * @var array{status: string, kanvas_lead_ids: int[], zoho_ids: string[], pulled_lead_ids: int[], converted: bool}|null
     */
    private ?array $duplicateCheck = null;

    #[Override]
    public function execute(): array
    {
        $zohoOwnerId = $this->duplicateZohoOwnerId();

        if ($zohoOwnerId === null) {
            return parent::execute();
        }

        $leadReceiver = $this->loadReceiver();
        [$email, $phones] = $this->contactsFromPayload($this->buildPayload($leadReceiver));
        $this->duplicateCheck = $this->checkForDuplicates($leadReceiver, $email, $phones);

        if ($this->duplicateCheck['status'] !== self::STATUS_MATCHED) {
            return parent::execute();
        }

        return $this->reassignExistingLead($leadReceiver, $zohoOwnerId);
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

    protected function pullConvertedLeadDeal(Lead $lead): void
    {
        new PullConvertedLeadDealAction(
            $lead,
            new ZohoService($this->receiver->app, $this->receiver->company)
        )->execute();
    }

    /**
     * Saved without workflows: the regular Zoho push rewrites every mapped field from the Kanvas copy,
     * and the Zoho lead may have been edited since. That push also rebuilds Description, so the origin
     * line goes on both sides. The custom field covers a matched lead whose first push is still queued.
     */
    private function reassignExistingLead(LeadReceiver $leadReceiver, string $zohoOwnerId): array
    {
        $attempt = $this->createAttempt($leadReceiver);
        $lead = $this->mostRecentMatchedLead();
        $origin = 'New submission from ' . $leadReceiver->name . ' on ' . now()->format('m/d/Y') . '.';
        $converted = $this->duplicateCheck['converted'];

        if ($lead !== null) {
            $owner = $converted ? null : $this->duplicateOwner();

            if ($owner !== null) {
                $lead->leads_owner_id = $owner->getId();
            }

            $lead->description = $this->appendOrigin($lead->description, $origin);
            $lead->disableWorkflows();
            $lead->saveOrFail();

            if (! $converted) {
                $lead->set(CustomFieldEnum::ZOHO_DUPLICATE_OWNER_ID->value, $zohoOwnerId);
            }

            $attempt->leads_id = $lead->getId();
            $attempt->processed = 1;
            $attempt->saveOrFail();
        }

        $zohoLeadId = Str::trimToNull((string) $lead?->get(CustomFieldEnum::ZOHO_LEAD_ID->value))
            ?? ($this->duplicateCheck['zoho_ids'][0] ?? null);
        $zohoUpdated = ! $converted
            && $zohoLeadId !== null
            && $this->reassignInZoho($zohoLeadId, $zohoOwnerId, $origin);

        return [
            'message' => $converted
                ? 'Existing lead is already a deal in Zoho, no lead created via receiver ' . $leadReceiver->uuid
                : 'Existing lead reassigned via receiver ' . $leadReceiver->uuid,
            'receiver' => $leadReceiver->getId(),
            'lead_id' => $lead?->getId(),
            'lead' => $lead?->toArray(),
            'zoho_lead_id' => $zohoLeadId,
            'zoho_updated' => $zohoUpdated,
            'duplicate_check' => $this->duplicateCheck,
        ];
    }

    private function reassignInZoho(string $zohoLeadId, string $zohoOwnerId, string $origin): bool
    {
        $module = $this->zohoLeadsModule();
        $data = ['Owner' => $zohoOwnerId];

        try {
            $current = $module->get($zohoLeadId);

            // An empty read must not replace the whole description with the one line.
            if ($current->getId() !== null) {
                $data['Description'] = $this->appendOrigin($current->getData()['Description'] ?? null, $origin);
            }
        } catch (Throwable $e) {
            report($e);
        }

        try {
            $module->update($zohoLeadId, $data);
        } catch (Throwable $e) {
            report($e);

            return false;
        }

        return true;
    }

    /**
     * Each page of a form can post on its own, so the same line is written once.
     */
    private function appendOrigin(?string $description, string $origin): string
    {
        $description = trim((string) $description);

        if (str_contains($description, $origin)) {
            return $description;
        }

        return $description === '' ? $origin : $description . "\n" . $origin;
    }

    private function mostRecentMatchedLead(): ?Lead
    {
        $ids = [
            ...$this->duplicateCheck['kanvas_lead_ids'],
            ...$this->duplicateCheck['pulled_lead_ids'],
        ];

        if ($ids === []) {
            return null;
        }

        return Lead::getByIdFromCompanyApp(max($ids), $this->receiver->company, $this->receiver->app);
    }

    /**
     * Kanvas first: it is a local query and catches leads whose Zoho push is still queued. Zoho is only
     * searched when Kanvas has nothing; a Kanvas match carries its ZOHO_LEAD_ID for the update.
     *
     * @param string[] $phones
     * @return array{status: string, kanvas_lead_ids: int[], zoho_ids: string[], pulled_lead_ids: int[], converted: bool}
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
            pulledLeadIds: $this->pullZohoLeads($leadReceiver, $zohoLeads),
            converted: array_any($zohoLeads, ZohoService::isConvertedLead(...))
        );
    }

    /**
     * A lead that lived only in Zoho is brought into Kanvas, so Zoho's update webhooks find it by
     * ZOHO_LEAD_ID from now on and the next submission matches it locally. The pull runs without
     * workflows, so nothing is written back to Zoho. A failed pull is reported and skipped: the Zoho
     * record is still reassigned. A converted lead also brings its deal, so documents sent for the
     * lead later reach that deal.
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

            if ($lead === null) {
                continue;
            }

            $pulled[] = $lead->getId();

            if (! ZohoService::isConvertedLead($record)) {
                continue;
            }

            try {
                $this->pullConvertedLeadDeal($lead);
            } catch (Throwable $e) {
                report($e);
            }
        }

        return $pulled;
    }

    /**
     * Matches on the lead's own email and phone columns, not People contacts: matching a phone there is
     * a REGEXP over every contact of the company. The lead's phone is its first phone contact, stored
     * normalized, so the incoming numbers are normalized the same way.
     *
     * @param string[] $phones
     * @return int[]
     */
    private function findKanvasLeadIds(?string $email, array $phones): array
    {
        $phones = array_values(array_filter(array_map(
            fn (string $phone) => Contact::normalizeValue($phone, ContactTypeEnum::PHONE->value),
            $phones
        )));

        if ($email === null && $phones === []) {
            return [];
        }

        return Lead::query()
            ->fromApp($this->receiver->app)
            ->fromCompany($this->receiver->company)
            ->notDeleted()
            ->where(function ($query) use ($email, $phones) {
                if ($email !== null) {
                    $query->orWhere('email', $email);
                }

                if ($phones !== []) {
                    $query->orWhereIn('phone', $phones);
                }
            })
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
     * A misconfigured user id must not lose the reassignment: the lead keeps its current owner.
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

    /**
     * @param int[] $kanvasLeadIds
     * @param string[] $zohoIds
     * @param int[] $pulledLeadIds
     */
    private function checkResult(
        string $status,
        array $kanvasLeadIds = [],
        array $zohoIds = [],
        array $pulledLeadIds = [],
        bool $converted = false
    ): array {
        return [
            'status' => $status,
            'kanvas_lead_ids' => $kanvasLeadIds,
            'zoho_ids' => $zohoIds,
            'pulled_lead_ids' => $pulledLeadIds,
            'converted' => $converted,
        ];
    }
}
