<?php

declare(strict_types=1);

namespace Kanvas\Guild\Leads\Actions;

use Baka\Support\Str;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Kanvas\Guild\Customers\Actions\ApplyDoNotContactAction;
use Kanvas\Guild\Customers\Enums\ConsentMatchEnum;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Contracts\CrmLeadDriver;
use Kanvas\Guild\Leads\DataTransferObject\CrmLeadPlan;
use Kanvas\Guild\Leads\DataTransferObject\CrmLeadSync;
use Kanvas\Guild\Leads\DataTransferObject\CrmLeadSyncResult;
use Kanvas\Guild\Leads\Enums\CrmLeadSyncOutcomeEnum;
use Kanvas\Guild\Leads\Exceptions\CrmLeadSyncException;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Triggers\Enums\TriggersEnum;
use Kanvas\Workflow\Enums\WorkflowEnum;
use Throwable;

/**
 * Brings a CRM customer and prospect into Kanvas in one call: finds the people and the lead the CRM's
 * ids point at, adopts or creates what is missing, and applies what the CRM sent.
 *
 * Everything that can be refused is checked before the first write, so a refusal leaves nothing behind.
 * The rows live on `crm` and the identity custom fields on `ecosystem`, so the whole is not one
 * transaction: the crm rows commit together, the custom fields follow, and if those fail the rows this
 * request created are soft-deleted again. The same request can be sent again and converges.
 */
class SyncCrmLeadAction
{
    private const int REPLAY_TTL_SECONDS = 600;
    private const int LOCK_SECONDS = 10;

    protected CrmLeadDriver $driver;

    /**
     * The driver is injected only by tests; the request's own type names it otherwise.
     */
    public function __construct(
        protected readonly CrmLeadSync $data,
        ?CrmLeadDriver $driver = null
    ) {
        $this->driver = $driver ?? $this->data->identity->type->driver();
    }

    public function execute(): CrmLeadSyncResult
    {
        if (! $this->driver->isConfigured($this->data->company)) {
            throw CrmLeadSyncException::notConfigured($this->data->identity->type);
        }

        $replayed = $this->replay();

        if ($replayed instanceof CrmLeadSyncResult) {
            return $replayed;
        }

        return $this->underLock(
            $this->requestKey() . ':lock',
            fn (): CrmLeadSyncResult => $this->replay() ?? $this->runLocked()
        );
    }

    private function runLocked(): CrmLeadSyncResult
    {
        $leadId = $this->data->identity->lead_id;
        $run = fn (): CrmLeadSyncResult => $this->underLock($this->clientLockKey(), fn (): CrmLeadSyncResult => $this->run());

        $result = $leadId === null
            ? $run()
            : $this->underLock($this->leadLockKey($leadId), $run);

        Cache::put($this->requestKey(), $this->replayPayload($result), self::REPLAY_TTL_SECONDS);

        return $result;
    }

    private function run(): CrmLeadSyncResult
    {
        $plan = new ResolveCrmLeadPlanAction($this->data, $this->driver)->execute();
        $followers = new SyncCrmLeadFollowersAction($this->data);
        $participants = new SyncCrmLeadParticipantsAction($this->data, $plan->lead);

        $ownerId = $this->validate($plan);
        $followers->prepare();
        $participants->prepare();

        return $this->write($plan, $ownerId, $participants, $followers);
    }

    /**
     * What a create needs is only checked when the plan creates: opening or adopting a lead works with
     * whatever the CRM managed to send. Errors that stop a create the dealer expected go to Sentry.
     */
    private function validate(CrmLeadPlan $plan): ?int
    {
        $customer = $this->data->customer;
        $info = $this->data->lead;

        if ($plan->createsPeople()) {
            $this->requireValue($customer?->normalizedFirstname(), 'customer.firstname');
            $this->requireValue($customer?->normalizedLastname(), 'customer.lastname');
        }

        $salesperson = Str::trimToNull($info?->salesperson?->name);
        $ownerId = $salesperson === null
            ? null
            : $this->driver->resolveOwnerId($salesperson, $this->data->company);

        if ($plan->createsLead()) {
            $this->requireValue(Str::trimToNull($info?->type?->name), 'lead.type.name');
            $this->requireValue(Str::trimToNull($info?->source?->name), 'lead.source.name');

            if ($ownerId === null) {
                $this->refuse(CrmLeadSyncException::ownerNotFound($salesperson));
            }
        }

        return $ownerId;
    }

    private function requireValue(?string $value, string $field): void
    {
        if ($value === null) {
            $this->refuse(CrmLeadSyncException::validationFailed($field . ' is required to create it', $field));
        }
    }

    private function refuse(CrmLeadSyncException $exception): never
    {
        if ($exception->shouldReport()) {
            report($exception);
        }

        throw $exception;
    }

    private function write(
        CrmLeadPlan $plan,
        ?int $ownerId,
        SyncCrmLeadParticipantsAction $participants,
        SyncCrmLeadFollowersAction $followers
    ): CrmLeadSyncResult {
        $people = $plan->people;
        $lead = $plan->lead;
        $warnings = $this->planWarnings($plan);

        DB::connection('crm')->transaction(function () use ($plan, $ownerId, $participants, &$people, &$lead, &$warnings): void {
            $customer = $this->data->customer;

            $people ??= new CreateCrmPeopleAction(
                $this->data->app,
                $this->data->company,
                $this->data->user,
                $customer
            )->execute();

            if ($customer !== null) {
                $warnings = [
                    ...$warnings,
                    ...new ApplyCrmCustomerAction($people, $customer, $this->data->app)->execute(),
                ];
            }

            $lead = new SaveCrmLeadAction($this->data, $this->driver, $people, $plan->lead, $ownerId)->execute();
            $participants->execute($lead);
        });

        try {
            $this->writeCustomFields($people, $lead);
            $followers->execute($lead);
            $this->applyDoNotContact($people, $lead);
        } catch (Throwable $exception) {
            $this->compensate($plan, $people, $lead);

            throw $exception;
        }

        if ($plan->createsLead()) {
            $this->fireWorkflows($lead);
        }

        return new CrmLeadSyncResult(
            $plan->outcome,
            $people,
            $lead,
            $plan->createsPeople(),
            $plan->createsLead(),
            false,
            $warnings
        );
    }

    /**
     * An opt-out reported by the CRM is honored person-wide like any other, and before the workflows
     * fire so a new lead is never contacted. It runs after the lead exists so that lead is flagged
     * too, and it only ever silences: nothing here lifts one. A failure is not swallowed, since a
     * missed opt-out leaves the person contactable; the request can be sent again.
     */
    private function applyDoNotContact(People $people, Lead $lead): void
    {
        $customer = $this->data->customer;

        if ($customer === null || ! $this->driver->requestsDoNotContact($customer)) {
            return;
        }

        new ApplyDoNotContactAction(
            people: $people,
            sourceChannel: 'crm_sync:' . $this->data->identity->type->value,
            lead: $lead,
            reason: 'The CRM reports the customer opted out',
            match: ConsentMatchEnum::EXACT
        )->execute();
    }

    /**
     * @return list<array{code: string, field: string}>
     */
    private function planWarnings(CrmLeadPlan $plan): array
    {
        $warnings = [];

        if ($plan->ambiguous) {
            $warnings[] = ['code' => 'multiple_people_matched', 'field' => 'customer'];
        }

        if ($this->data->identity->lead_id === null) {
            $warnings[] = ['code' => 'lead_id_missing', 'field' => 'identity.lead_id'];
        }

        return $warnings;
    }

    /**
     * What the driver derives is computed before anything is written, so a failure there leaves no
     * custom field behind; then the identity goes first, since it is what lets the next request find
     * these rows again.
     */
    private function writeCustomFields(People $people, Lead $lead): void
    {
        $customerFields = $this->customerFields();
        $leadFields = $this->leadFields();
        $clientField = $this->driver->clientIdField();

        if ($clientField !== null) {
            $people->set($clientField, $this->data->identity->client_id);
        }

        if ($this->data->identity->lead_id !== null) {
            $lead->set($this->driver->leadIdField(), $this->data->identity->lead_id);
        }

        foreach ($customerFields as $name => $value) {
            $people->set($name, $value);
        }

        foreach ($leadFields as $name => $value) {
            $lead->set($name, $value);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function customerFields(): array
    {
        return $this->data->customer === null ? [] : $this->driver->customerCustomFields($this->data->customer);
    }

    /**
     * @return array<string, mixed>
     */
    private function leadFields(): array
    {
        return $this->data->lead === null ? [] : $this->driver->leadCustomFields($this->data->lead);
    }

    /**
     * Soft-deletes only what this request created, so no later lookup can find it, and then drops its
     * custom fields so the identity does not outlive the row. Each step is best effort and reported:
     * a failure in one must not stop the rest. Edits to rows that already existed are left in place.
     */
    private function compensate(CrmLeadPlan $plan, People $people, Lead $lead): void
    {
        $created = [];

        if ($plan->createsLead()) {
            $created[] = $lead;
        }

        if ($plan->createsPeople()) {
            $created[] = $people;
        }

        foreach ($created as $model) {
            try {
                $model->softDelete();
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        foreach ($created as $model) {
            try {
                $model->deleteAllCustomFields();
            } catch (Throwable $exception) {
                report($exception);
            }
        }
    }

    /**
     * Only what a lead made by hand would trigger, and only for a lead created here: the contacts are
     * saved so its channels open, and the AI is told it is new. A failure here must not undo a sync
     * that already succeeded.
     */
    private function fireWorkflows(Lead $lead): void
    {
        try {
            $params = ['company' => $this->data->company, 'app' => $this->data->app];

            foreach ($lead->people->contacts as $contact) {
                $contact->fireWorkflow(WorkflowEnum::CONTACT_SAVED->value, true, $params);
            }

            $lead->enableWorkflows();
            $lead->fireWorkflow(
                WorkflowEnum::TRIGGER_AI->value,
                true,
                $params + ['trigger_type' => TriggersEnum::NEW_LEAD->value]
            );
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    private function replay(): ?CrmLeadSyncResult
    {
        $stored = Cache::get($this->requestKey());

        if (! is_array($stored)) {
            return null;
        }

        $people = People::query()->where('id', $stored['people_id'])->notDeleted()->first();
        $lead = Lead::query()->where('id', $stored['lead_id'])->notDeleted()->first();

        // Rows that are gone are not replayed; the request runs again.
        if (! $people instanceof People || ! $lead instanceof Lead) {
            return null;
        }

        return new CrmLeadSyncResult(
            CrmLeadSyncOutcomeEnum::from($stored['outcome']),
            $people,
            $lead,
            $stored['people_created'],
            $stored['lead_created'],
            true,
            $stored['warnings']
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function replayPayload(CrmLeadSyncResult $result): array
    {
        return [
            'outcome' => $result->outcome->value,
            'people_id' => $result->people->getId(),
            'lead_id' => $result->lead->getId(),
            'people_created' => $result->peopleCreated,
            'lead_created' => $result->leadCreated,
            'warnings' => $result->warnings,
        ];
    }

    /**
     * @template T
     *
     * @param callable(): T $callback
     *
     * @return T
     */
    private function underLock(string $key, callable $callback): mixed
    {
        try {
            return Cache::lock($key, self::LOCK_SECONDS)->block(self::LOCK_SECONDS + 1, $callback);
        } catch (LockTimeoutException) {
            throw CrmLeadSyncException::lockedRetry();
        }
    }

    private function requestKey(): string
    {
        return sprintf(
            'crm_lead_sync:%s:%s:%s:%s',
            $this->data->app->getId(),
            $this->data->company->getId(),
            $this->data->user->getId(),
            $this->data->requestId
        );
    }

    private function clientLockKey(): string
    {
        return sprintf(
            'crm_lead_sync_client:%s:%s:%s:%s',
            $this->data->app->getId(),
            $this->data->company->getId(),
            $this->data->identity->type->value,
            $this->data->identity->client_id
        );
    }

    /**
     * The webhook's own lock for a lead, built the way its sync builds it (ids concatenated with no
     * separator), so a webhook for this prospect waits for this request and the reverse. Changing this
     * string stops them excluding each other.
     */
    private function leadLockKey(string $leadId): string
    {
        return 'lead_sync:'
            . $this->data->app->getId()
            . $this->data->company->getId()
            . ':' . $this->driver->leadIdField()
            . ':' . $leadId;
    }
}
