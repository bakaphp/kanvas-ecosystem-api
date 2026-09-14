<?php

declare(strict_types=1);

namespace Kanvas\Guild\Customers\Actions;

use Baka\Users\Contracts\UserInterface;
use Illuminate\Support\Facades\DB;
use Kanvas\Guild\Customers\Models\Contact;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Duplicates\Actions\MarkDuplicateGroupsResolvedAction;
use Kanvas\Guild\Organizations\Models\OrganizationPeople;
use Kanvas\NervousSystem\Ledger\Actions\AppendEventAction;
use Kanvas\NervousSystem\Ledger\DataTransferObject\Event as LedgerEventData;
use Kanvas\NervousSystem\Ledger\Enums\EventStatusEnum;
use Kanvas\Workflow\Enums\WorkflowEnum;
use RuntimeException;
use Throwable;

class MergePeopleAction
{
    public function __construct(
        public readonly People $source,
        public readonly People $target,
        public readonly ?UserInterface $user = null,
    ) {
    }

    public function execute(): People
    {
        if ((int) $this->source->id === (int) $this->target->id) {
            throw new RuntimeException(
                'Cannot merge a people into itself (source and target are the same row).'
            );
        }

        if ((int) $this->source->apps_id !== (int) $this->target->apps_id
            || (int) $this->source->companies_id !== (int) $this->target->companies_id) {
            throw new RuntimeException(
                'Cannot merge people across tenants. '
                . "source apps_id={$this->source->apps_id}/companies_id={$this->source->companies_id} "
                . "vs target apps_id={$this->target->apps_id}/companies_id={$this->target->companies_id}."
            );
        }

        if ((bool) $this->source->is_deleted) {
            throw new RuntimeException(
                "People {$this->source->id} is already soft-deleted — refusing to merge a deleted row."
            );
        }

        $sourceId = (int) $this->source->id;
        $targetId = (int) $this->target->id;
        $crmSchema = DB::connection('crm')->getSchemaBuilder();

        $guildRewrittenCount = 0;

        if ($crmSchema->hasTable('leads') && $crmSchema->hasColumn('leads', 'people_id')) {
            $guildRewrittenCount += DB::connection('crm')->table('leads')
                ->where('people_id', $sourceId)
                ->update(['people_id' => $targetId]);
        }

        if ($crmSchema->hasTable('deals') && $crmSchema->hasColumn('deals', 'people_id')) {
            $guildRewrittenCount += DB::connection('crm')->table('deals')
                ->where('people_id', $sourceId)
                ->update(['people_id' => $targetId]);
        }

        if ($crmSchema->hasTable('leads_participants') && $crmSchema->hasColumn('leads_participants', 'peoples_id')) {
            $guildRewrittenCount += DB::connection('crm')->table('leads_participants')
                ->where('peoples_id', $sourceId)
                ->update(['peoples_id' => $targetId]);
        }

        $employmentHistoryRebound = $this->rebindEmploymentHistory($sourceId, $targetId);
        $organizationsPeoplesRebound = $this->rebindOrganizationsPeoples($sourceId, $targetId);
        $contactsTransferred = $this->transferContacts($sourceId, $targetId);
        $addressesTransferred = $this->transferAddresses($sourceId, $targetId);
        $customFieldsAdopted = $this->adoptCustomFields();

        $this->source->is_deleted = true;
        if ($crmSchema->hasColumn($this->source->getTable(), 'merged_into_people_id')) {
            $this->source->merged_into_people_id = $targetId;
        }
        $this->source->save();

        $this->recordMergeEvent(
            $sourceId,
            $targetId,
            $guildRewrittenCount,
            $employmentHistoryRebound,
            $organizationsPeoplesRebound,
            $contactsTransferred,
            $addressesTransferred,
            $customFieldsAdopted,
        );

        new MarkDuplicateGroupsResolvedAction(
            entityType: People::class,
            appsId: (int) $this->target->apps_id,
            companiesId: (int) $this->target->companies_id,
            sourceId: $sourceId,
            targetId: $targetId,
            user: $this->user,
        )->execute();

        try {
            $this->target->fireWorkflow(WorkflowEnum::AFTER_MERGE->value, true, [
                'app' => $this->target->app,
                'source_id' => $sourceId,
                'target_id' => $targetId,
            ]);
        } catch (Throwable $e) {
            report($e);
        }

        return $this->target->refresh();
    }

    /**
     * Move every (source, organizations_id) row to (target, organizations_id). When the target
     * already has a row for the same org+position, the source row is deleted.
     */
    private function rebindEmploymentHistory(int $sourceId, int $targetId): int
    {
        $rebound = 0;
        $sourceRows = DB::connection('crm')->table('peoples_employment_history')
            ->where('peoples_id', $sourceId)
            ->get();

        foreach ($sourceRows as $row) {
            $existingOnTarget = DB::connection('crm')->table('peoples_employment_history')
                ->where('peoples_id', $targetId)
                ->where('organizations_id', $row->organizations_id)
                ->where('position', $row->position)
                ->exists();

            if ($existingOnTarget) {
                DB::connection('crm')->table('peoples_employment_history')->where('id', $row->id)->delete();

                continue;
            }

            DB::connection('crm')->table('peoples_employment_history')
                ->where('id', $row->id)
                ->update(['peoples_id' => $targetId]);
            $rebound++;
        }

        return $rebound;
    }

    /**
     * Move every (source, organizations_id) row to (target, organizations_id). When the target
     * already has the same organizations_id, the source row is deleted (no need to keep a
     * duplicate pivot row).
     */
    private function rebindOrganizationsPeoples(int $sourceId, int $targetId): int
    {
        $rebound = 0;
        $sourceRows = OrganizationPeople::query()
            ->where('peoples_id', $sourceId)
            ->get();

        foreach ($sourceRows as $row) {
            $existingOnTarget = OrganizationPeople::query()
                ->where('peoples_id', $targetId)
                ->where('organizations_id', $row->organizations_id)
                ->first();

            if ($existingOnTarget !== null) {
                $row->delete();

                continue;
            }

            $row->peoples_id = $targetId;
            $row->save();
            $rebound++;
        }

        return $rebound;
    }

    /**
     * Move every source contact to the target. When the target already has an equivalent contact
     * (same normalized value + type), the source's copy is deleted instead.
     */
    private function transferContacts(int $sourceId, int $targetId): int
    {
        $transferred = 0;

        $existingTargetKeys = Contact::query()
            ->where('peoples_id', $targetId)
            ->get(['value', 'contacts_types_id'])
            ->map(fn ($c) => Contact::normalizeValue($c->value, $c->contacts_types_id) . '_' . $c->contacts_types_id)
            ->all();

        $sourceContacts = Contact::query()->where('peoples_id', $sourceId)->get();

        foreach ($sourceContacts as $contact) {
            $key = Contact::normalizeValue($contact->value, $contact->contacts_types_id) . '_' . $contact->contacts_types_id;

            if (in_array($key, $existingTargetKeys, true)) {
                $contact->delete();

                continue;
            }

            $contact->peoples_id = $targetId;
            $contact->save();
            $existingTargetKeys[] = $key;
            $transferred++;
        }

        return $transferred;
    }

    /**
     * Move every source address to the target. When the target already has an identical address,
     * the source's copy is deleted instead.
     */
    private function transferAddresses(int $sourceId, int $targetId): int
    {
        $transferred = 0;

        $addressKey = fn ($a) => implode('_', [
            strtolower(trim((string) $a->address)),
            strtolower(trim((string) $a->address_2)),
            strtolower(trim((string) $a->city)),
            strtolower(trim((string) $a->state)),
            strtolower(trim((string) $a->zip)),
        ]);

        $existingTargetKeys = DB::connection('crm')->table('peoples_address')
            ->where('peoples_id', $targetId)
            ->get()
            ->map($addressKey)
            ->all();

        $sourceAddresses = DB::connection('crm')->table('peoples_address')
            ->where('peoples_id', $sourceId)
            ->get();

        foreach ($sourceAddresses as $address) {
            $key = $addressKey($address);

            if (in_array($key, $existingTargetKeys, true)) {
                DB::connection('crm')->table('peoples_address')->where('id', $address->id)->delete();

                continue;
            }

            DB::connection('crm')->table('peoples_address')
                ->where('id', $address->id)
                ->update(['peoples_id' => $targetId]);
            $existingTargetKeys[] = $key;
            $transferred++;
        }

        // Both people carried their own current address; merging them would otherwise leave the
        // target with two, and readers resolve whichever row happens to sort first.
        $this->collapseDefaultAddresses($targetId);

        return $transferred;
    }

    /**
     * Leave the target with exactly one current address: the newest row already flagged as one,
     * or the newest row overall when the merge left none.
     */
    private function collapseDefaultAddresses(int $targetId): void
    {
        $addresses = DB::connection('crm')->table('peoples_address')
            ->where('peoples_id', $targetId)
            ->where('is_deleted', 0)
            ->orderByDesc('id')
            ->get();

        if ($addresses->isEmpty()) {
            return;
        }

        $keep = $addresses->firstWhere('is_default', 1) ?? $addresses->first();

        DB::connection('crm')->table('peoples_address')
            ->where('peoples_id', $targetId)
            ->where('id', '!=', $keep->id)
            ->update(['is_default' => 0]);

        DB::connection('crm')->table('peoples_address')
            ->where('id', $keep->id)
            ->update(['is_default' => 1]);
    }

    /**
     * Adopt each of the source's custom fields onto the target when the target doesn't already
     * have that field name. A field both rows already carry (e.g. each has its own distinct
     * salesforce_contact_id) is a real conflict — left untouched for a later merge phase.
     */
    private function adoptCustomFields(): int
    {
        $sourceFields = $this->source->getAll();
        $adopted = 0;

        foreach ($sourceFields as $name => $value) {
            if ($this->target->get($name) !== null) {
                continue;
            }

            $this->target->set($name, $value);
            $adopted++;
        }

        return $adopted;
    }

    /**
     * Append the merge to the ledger so there's a durable, queryable audit trail of what merged
     * into what. Best-effort: the FK rewrites + soft-delete already committed, so a ledger outage
     * must not fail an otherwise-successful merge.
     */
    private function recordMergeEvent(
        int $sourceId,
        int $targetId,
        int $guildRewrittenCount,
        int $employmentHistoryRebound,
        int $organizationsPeoplesRebound,
        int $contactsTransferred,
        int $addressesTransferred,
        int $customFieldsAdopted,
    ): void {
        try {
            new AppendEventAction(
                new LedgerEventData(
                    app: $this->target->app,
                    company: $this->target->company,
                    sourceDomain: 'Guild',
                    eventType: 'guild.people.merged',
                    status: EventStatusEnum::INFO,
                    sourceEntityType: People::class,
                    sourceEntityId: $targetId,
                    actorType: $this->user !== null ? 'User' : 'System',
                    actorId: $this->user?->getId(),
                    payload: [
                        'source_people_id' => $sourceId,
                        'source_name' => $this->source->firstname . ' ' . $this->source->lastname,
                        'target_people_id' => $targetId,
                        'target_name' => $this->target->firstname . ' ' . $this->target->lastname,
                        'guild_rows_rewritten' => $guildRewrittenCount,
                        'employment_history_rebound' => $employmentHistoryRebound,
                        'organizations_peoples_rebound' => $organizationsPeoplesRebound,
                        'contacts_transferred' => $contactsTransferred,
                        'addresses_transferred' => $addressesTransferred,
                        'custom_fields_adopted' => $customFieldsAdopted,
                    ],
                ),
            )->execute();
        } catch (Throwable $e) {
            report($e);
        }
    }
}
