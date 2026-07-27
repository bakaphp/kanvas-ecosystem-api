<?php

declare(strict_types=1);

namespace Kanvas\Guild\Organizations\Actions;

use Baka\Users\Contracts\UserInterface;
use Illuminate\Support\Facades\DB;
use Kanvas\Guild\Organizations\Models\Organization;
use Kanvas\Guild\Organizations\Models\OrganizationPeople;
use Kanvas\NervousSystem\Ledger\Actions\AppendEventAction;
use Kanvas\NervousSystem\Ledger\DataTransferObject\Event as LedgerEventData;
use Kanvas\NervousSystem\Ledger\Enums\EventStatusEnum;
use RuntimeException;
use Throwable;

/**
 * Merges two Guild Organization rows: rewrites every FK from `source` to `target`, then
 * soft-deletes `source`. The cleanup tool for the auto-create-vendor pipeline — bulk import
 * inevitably produces duplicates ("ACME", "ACME Corp", "Acme Corporation"), and this Action
 * lets an operator consolidate them.
 *
 * Tables touched:
 *   Scribe (accounting DB):
 *     - invoices.customer_organization_id
 *     - quotes.customer_organization_id
 *     - sales_receipts.customer_organization_id
 *     - bills.vendor_organization_id
 *     - expenses.vendor_organization_id
 *   Guild (crm DB) — rewritten inside one `crm` transaction along with the soft-delete, so a
 *   failure partway through can't leave some FKs on the target while source is still live:
 *     - leads.organization_id
 *     - deals.organization_id  (if present in this app)
 *     - peoples_employment_history.organizations_id
 *     - organizations_peoples  (merge, dedup on (organization_id, peoples_id))
 *   Custom fields (`apps_custom_fields`, `ecosystem` DB): adopted onto the target when the target
 *   doesn't already have that field name (e.g. salesforce_account_id) — a name both rows already
 *   carry is a real conflict, left untouched for a later merge phase.
 *
 * Source Organization is soft-deleted at the end (is_deleted=1). NOT hard-deleted — preserves
 * audit trail of what got merged into what (look at the source row's `merged_into_organization_id`
 * convention or check the emitted ledger event).
 *
 * Invariants enforced:
 *   - source and target must belong to the SAME (apps_id, companies_id) tenant
 *   - source != target (no-op merge is rejected — likely a UI bug)
 *   - source must not already be soft-deleted (would orphan FKs that point at the target)
 */
class MergeOrganizationsAction
{
    public function __construct(
        public readonly Organization $source,
        public readonly Organization $target,
        public readonly ?UserInterface $user = null,
    ) {
    }

    /**
     * @return Organization the target (with refreshed in-memory state)
     */
    public function execute(): Organization
    {
        if ((int) $this->source->id === (int) $this->target->id) {
            throw new RuntimeException(
                'Cannot merge an organization into itself (source and target are the same row).'
            );
        }

        if ((int) $this->source->apps_id !== (int) $this->target->apps_id
            || (int) $this->source->companies_id !== (int) $this->target->companies_id) {
            throw new RuntimeException(
                'Cannot merge organizations across tenants. '
                . "source apps_id={$this->source->apps_id}/companies_id={$this->source->companies_id} "
                . "vs target apps_id={$this->target->apps_id}/companies_id={$this->target->companies_id}."
            );
        }

        if ((bool) $this->source->is_deleted) {
            throw new RuntimeException(
                "Organization {$this->source->id} is already soft-deleted — refusing to merge a deleted row."
            );
        }

        $sourceId = (int) $this->source->id;
        $targetId = (int) $this->target->id;

        // ── Scribe side (accounting DB) ──
        $scribeUpdates = [
            'invoices' => 'customer_organization_id',
            'quotes' => 'customer_organization_id',
            'sales_receipts' => 'customer_organization_id',
            'bills' => 'vendor_organization_id',
            'expenses' => 'vendor_organization_id',
        ];
        $scribeRewrittenCount = 0;
        DB::connection('accounting')->transaction(function () use ($scribeUpdates, $sourceId, $targetId, &$scribeRewrittenCount): void {
            foreach ($scribeUpdates as $table => $column) {
                $scribeRewrittenCount += DB::connection('accounting')
                    ->table($table)
                    ->where($column, $sourceId)
                    ->update([$column => $targetId]);
            }
        });

        // ── Guild side (crm DB) ── Lead + Deal both live on the `crm` connection per Guild BaseModel.
        // Wrapped in one transaction so a failure partway through (e.g. the employment-history
        // rewrite) can't leave some FKs pointed at the target while others still point at a
        // source that's about to be soft-deleted.
        $guildRewrittenCount = 0;
        $organizationsPeoplesRebound = 0;
        $crmSchema = DB::connection('crm')->getSchemaBuilder();

        DB::connection('crm')->transaction(function () use ($sourceId, $targetId, $crmSchema, &$guildRewrittenCount, &$organizationsPeoplesRebound): void {
            if ($crmSchema->hasTable('leads') && $crmSchema->hasColumn('leads', 'organization_id')) {
                $guildRewrittenCount += DB::connection('crm')->table('leads')
                    ->where('organization_id', $sourceId)
                    ->update(['organization_id' => $targetId]);
            }

            if ($crmSchema->hasTable('deals') && $crmSchema->hasColumn('deals', 'organization_id')) {
                $guildRewrittenCount += DB::connection('crm')->table('deals')
                    ->where('organization_id', $sourceId)
                    ->update(['organization_id' => $targetId]);
            }

            // Employment history points each person's role at an org — rebind so a merged-away
            // duplicate doesn't leave roles hanging off a soft-deleted org (the genuine-current-
            // employer check reads these).
            if ($crmSchema->hasTable('peoples_employment_history') && $crmSchema->hasColumn('peoples_employment_history', 'organizations_id')) {
                $guildRewrittenCount += DB::connection('crm')->table('peoples_employment_history')
                    ->where('organizations_id', $sourceId)
                    ->update(['organizations_id' => $targetId]);
            }

            // organizations_peoples — dedup on collision: if (target, peoples_id) already exists,
            // delete the source row outright rather than violating the unique constraint.
            $organizationsPeoplesRebound = $this->rebindOrganizationPeoples($sourceId, $targetId);

            // ── Soft-delete source + stamp the audit pointer ──
            $this->source->is_deleted = true;
            if ($crmSchema->hasColumn($this->source->getTable(), 'merged_into_organization_id')) {
                $this->source->merged_into_organization_id = $targetId;
            }
            $this->source->save();
        });

        $customFieldsAdopted = $this->adoptCustomFields();

        $this->recordMergeEvent(
            $sourceId,
            $targetId,
            $scribeRewrittenCount,
            $guildRewrittenCount,
            $organizationsPeoplesRebound,
            $customFieldsAdopted,
        );

        return $this->target->refresh();
    }

    /**
     * Append the merge to the ledger so there's a durable, queryable audit trail of what merged
     * into what. Best-effort: the FK rewrites + soft-delete already committed, so a ledger outage
     * must not fail an otherwise-successful merge (the `merged_into_organization_id` pointer on the
     * row is the second, on-record trail).
     */
    private function recordMergeEvent(
        int $sourceId,
        int $targetId,
        int $scribeRewrittenCount,
        int $guildRewrittenCount,
        int $organizationsPeoplesRebound,
        int $customFieldsAdopted,
    ): void {
        try {
            new AppendEventAction(
                new LedgerEventData(
                    app: $this->target->app,
                    company: $this->target->company,
                    sourceDomain: 'Guild',
                    eventType: 'guild.organization.merged',
                    status: EventStatusEnum::INFO,
                    sourceEntityType: Organization::class,
                    sourceEntityId: $targetId,
                    actorType: $this->user !== null ? 'User' : 'System',
                    actorId: $this->user?->getId(),
                    payload: [
                        'source_organization_id' => $sourceId,
                        'source_name' => $this->source->name,
                        'target_organization_id' => $targetId,
                        'target_name' => $this->target->name,
                        'scribe_rows_rewritten' => $scribeRewrittenCount,
                        'guild_rows_rewritten' => $guildRewrittenCount,
                        'organizations_peoples_rebound' => $organizationsPeoplesRebound,
                        'custom_fields_adopted' => $customFieldsAdopted,
                    ],
                ),
            )->execute();
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * Adopt each of the source's custom fields onto the target when the target doesn't already
     * have that field name. A field both rows already carry (e.g. each has its own distinct
     * salesforce_account_id) is a real conflict — left untouched for a later merge phase.
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
     * Move every (source, peoples_id) row to (target, peoples_id). When the target already has
     * the same peoples_id, the source row is deleted (no need to keep a duplicate pivot row).
     */
    private function rebindOrganizationPeoples(int $sourceId, int $targetId): int
    {
        $rebound = 0;
        $sourceRows = OrganizationPeople::query()
            ->where('organizations_id', $sourceId)
            ->get();

        foreach ($sourceRows as $row) {
            $existingOnTarget = OrganizationPeople::query()
                ->where('organizations_id', $targetId)
                ->where('peoples_id', $row->peoples_id)
                ->first();
            if ($existingOnTarget !== null) {
                $row->delete();

                continue;
            }
            $row->organizations_id = $targetId;
            $row->save();
            $rebound++;
        }

        return $rebound;
    }
}
