<?php

declare(strict_types=1);

namespace Kanvas\Guild\Leads\Actions;

use Baka\Users\Contracts\UserInterface;
use Illuminate\Support\Facades\DB;
use Kanvas\Guild\Duplicates\Actions\MarkDuplicateGroupsResolvedAction;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\NervousSystem\Ledger\Actions\AppendEventAction;
use Kanvas\NervousSystem\Ledger\DataTransferObject\Event as LedgerEventData;
use Kanvas\NervousSystem\Ledger\Enums\EventStatusEnum;
use Kanvas\Workflow\Enums\WorkflowEnum;
use RuntimeException;
use Throwable;

/**
 * Collapses a duplicate lead into a surviving one: every child row that points at the source is
 * repointed at the target, the source adopts nothing and is soft-deleted with a pointer back to the
 * survivor.
 *
 * Children live across four connections (crm, action_engine, intelligence, social) and several of
 * them carry a unique/composite key that includes leads_id — a blind UPDATE on those duplicates the
 * key and fails the whole merge, so they are rewritten row by row and the source's copy is dropped
 * when the target already holds an equivalent row.
 */
class MergeLeadAction
{
    /**
     * Child rows keyed by connection => [table, lead column, columns that (with the lead column)
     * form a unique key]. A non-empty third element switches the rewrite to the per-row path.
     *
     * @var array<string, list<array{0: string, 1: string, 2: list<string>}>>
     */
    private const array CHILD_TABLES = [
        'crm' => [
            ['deals', 'leads_id', []],
            ['leads_attempt', 'leads_id', []],
            ['leads_shared_history', 'leads_id', []],
            ['lead_campaign_recipients', 'leads_id', []],
            ['leads_participants', 'leads_id', ['peoples_id']],
            ['leads_access', 'leads_id', ['users_id']],
            ['leads_linked_sources', 'leads_id', ['source_id', 'source_leads_id']],
        ],
        'action_engine' => [
            ['engagements', 'leads_id', []],
            ['company_task_engagement_items', 'lead_id', ['task_list_item_id']],
        ],
        'intelligence' => [
            ['follow_up_logs', 'leads_id', []],
        ],
        'social' => [
            ['twilio_message_attempts', 'lead_id', []],
        ],
    ];

    public function __construct(
        public readonly Lead $source,
        public readonly Lead $target,
        public readonly ?UserInterface $user = null,
    ) {
    }

    public function execute(): Lead
    {
        $this->assertMergeable();

        $sourceId = (int) $this->source->getId();
        $targetId = (int) $this->target->getId();

        $rowsRewritten = 0;
        foreach (self::CHILD_TABLES as $connection => $tables) {
            foreach ($tables as [$table, $column, $uniqueWith]) {
                $rowsRewritten += $this->rewriteChildTable(
                    $connection,
                    $table,
                    $column,
                    $uniqueWith,
                    $sourceId,
                    $targetId
                );
            }
        }

        $customFieldsAdopted = $this->adoptCustomFields();
        $contactFieldsAdopted = $this->adoptContactFields();

        $this->source->is_deleted = true;
        $this->source->merged_into_leads_id = $targetId;
        $this->source->save();

        $this->recordMergeEvent($sourceId, $targetId, $rowsRewritten, $customFieldsAdopted, $contactFieldsAdopted);

        new MarkDuplicateGroupsResolvedAction(
            entityType: Lead::class,
            appsId: (int) $this->target->apps_id,
            companiesId: $this->target->companies_id,
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

    private function assertMergeable(): void
    {
        if ((int) $this->source->getId() === (int) $this->target->getId()) {
            throw new RuntimeException(
                'Cannot merge a lead into itself (source and target are the same row).'
            );
        }

        if ((int) $this->source->apps_id !== (int) $this->target->apps_id
            || $this->source->companies_id !== $this->target->companies_id) {
            throw new RuntimeException(
                'Cannot merge leads across tenants. '
                . "source apps_id={$this->source->apps_id}/companies_id={$this->source->companies_id} "
                . "vs target apps_id={$this->target->apps_id}/companies_id={$this->target->companies_id}."
            );
        }

        if ($this->source->is_deleted) {
            throw new RuntimeException(
                "Lead {$this->source->id} is already soft-deleted — refusing to merge a deleted row."
            );
        }
    }

    /**
     * @param list<string> $uniqueWith
     */
    private function rewriteChildTable(
        string $connection,
        string $table,
        string $column,
        array $uniqueWith,
        int $sourceId,
        int $targetId,
    ): int {
        $schema = DB::connection($connection)->getSchemaBuilder();

        if (! $schema->hasTable($table) || ! $schema->hasColumn($table, $column)) {
            return 0;
        }

        if ($uniqueWith === []) {
            return DB::connection($connection)->table($table)
                ->where($column, $sourceId)
                ->update([$column => $targetId]);
        }

        $rewritten = 0;
        $sourceRows = DB::connection($connection)->table($table)
            ->where($column, $sourceId)
            ->get();

        foreach ($sourceRows as $row) {
            $conflict = DB::connection($connection)->table($table)->where($column, $targetId);
            $sourceRow = DB::connection($connection)->table($table)->where($column, $sourceId);

            foreach ($uniqueWith as $keyColumn) {
                $conflict->where($keyColumn, $row->{$keyColumn});
                $sourceRow->where($keyColumn, $row->{$keyColumn});
            }

            if ($conflict->exists()) {
                $sourceRow->delete();

                continue;
            }

            $sourceRow->update([$column => $targetId]);
            $rewritten++;
        }

        return $rewritten;
    }

    /**
     * Adopt each of the source's custom fields onto the target when the target doesn't already carry
     * that field. A field both leads hold is a real conflict — the target's value wins.
     */
    private function adoptCustomFields(): int
    {
        $adopted = 0;

        foreach ($this->source->getAll() as $name => $value) {
            if ($this->target->get((string) $name) !== null) {
                continue;
            }

            $this->target->set((string) $name, $value);
            $adopted++;
        }

        return $adopted;
    }

    /**
     * The reachability columns live on the lead row itself, so a merge that only repoints children
     * silently loses the source's phone when the target was created from an email-only capture (and
     * vice versa). Only blanks on the target are filled — the target is the survivor of record.
     *
     * @return list<string>
     */
    private function adoptContactFields(): array
    {
        $adopted = [];

        foreach (['email', 'phone', 'firstname', 'lastname', 'description'] as $field) {
            if (trim((string) $this->target->{$field}) !== '' || trim((string) $this->source->{$field}) === '') {
                continue;
            }

            $this->target->{$field} = $this->source->{$field};
            $adopted[] = $field;
        }

        if ($adopted !== []) {
            $this->target->save();
        }

        return $adopted;
    }

    /**
     * Best-effort audit trail: the rewrites and the soft-delete already committed, so a ledger
     * outage must not fail an otherwise-successful merge.
     *
     * @param list<string> $contactFieldsAdopted
     */
    private function recordMergeEvent(
        int $sourceId,
        int $targetId,
        int $rowsRewritten,
        int $customFieldsAdopted,
        array $contactFieldsAdopted,
    ): void {
        try {
            new AppendEventAction(
                new LedgerEventData(
                    app: $this->target->app,
                    company: $this->target->company,
                    sourceDomain: 'Guild',
                    eventType: 'guild.lead.merged',
                    status: EventStatusEnum::INFO,
                    sourceEntityType: Lead::class,
                    sourceEntityId: $targetId,
                    actorType: $this->user !== null ? 'User' : 'System',
                    actorId: $this->user?->getId(),
                    payload: [
                        'source_lead_id' => $sourceId,
                        'source_title' => $this->source->title,
                        'target_lead_id' => $targetId,
                        'target_title' => $this->target->title,
                        'rows_rewritten' => $rowsRewritten,
                        'custom_fields_adopted' => $customFieldsAdopted,
                        'contact_fields_adopted' => $contactFieldsAdopted,
                    ],
                ),
            )->execute();
        } catch (Throwable $e) {
            report($e);
        }
    }
}
