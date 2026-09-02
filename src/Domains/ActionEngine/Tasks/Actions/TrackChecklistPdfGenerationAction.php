<?php

declare(strict_types=1);

namespace Kanvas\ActionEngine\Tasks\Actions;

use Illuminate\Support\Facades\Cache;
use Kanvas\ActionEngine\Tasks\Enums\ChecklistPdfGenerationEnum;
use Kanvas\ActionEngine\Tasks\Events\ChecklistGeneratePdfEvent;
use Kanvas\ActionEngine\Tasks\Support\ChecklistPdfContext;
use Kanvas\ActionEngine\Tasks\Support\ChecklistPdfEntry;
use Kanvas\Guild\Leads\Models\Lead;

class TrackChecklistPdfGenerationAction
{
    public const CUSTOM_FIELD = 'checklist.generate.pdf';

    /**
     * A null status means the generation is over and the entry goes away — success is the absence of
     * a record, see ChecklistPdfGenerationEnum.
     */
    public function __construct(
        protected ChecklistPdfContext $context,
        protected ?ChecklistPdfGenerationEnum $status
    ) {
    }

    /**
     * @return list<ChecklistPdfEntry>
     */
    public function execute(): array
    {
        /** @var Lead $lead */
        $lead = $this->context->engagement->lead;
        $taskId = $this->context->taskListItem->getId();

        // Keyed by lead, not by task: a task finishing must not del() the field out from under
        // another task on the same lead that just started generating.
        $lockKey = 'checklist_generate_pdf:' . $lead->getId();

        [$entries, $changed] = Cache::lock($lockKey, 10)
            ->block(10, fn (): array => $this->writeEntries($lead, $taskId));

        // Re-marking a task that is already in this state writes nothing, so there is nothing for
        // the client to re-read either.
        if ($changed) {
            ChecklistGeneratePdfEvent::dispatch((string) $lead->uuid);
        }

        return $entries;
    }

    /**
     * @return array{0: list<ChecklistPdfEntry>, 1: bool}
     */
    private function writeEntries(Lead $lead, int $taskId): array
    {
        $current = $this->readEntries($lead);

        $entries = array_values(array_filter(
            $current,
            fn (ChecklistPdfEntry $entry): bool => $entry->taskId !== $taskId
        ));

        if ($this->status !== null) {
            $companyAction = $this->context->taskListItem->companyAction;

            $entries[] = new ChecklistPdfEntry(
                actionId: (int) $companyAction->actions_id,
                companyActionId: $companyAction->getId(),
                taskId: $taskId,
                messageId: (int) $this->context->engagement->message_id,
                status: $this->status
            );
        }

        $stored = self::toStoredArray($entries);

        if ($stored === self::toStoredArray($current)) {
            return [$entries, false];
        }

        // set() ends in fireWorkflow(CREATE_CUSTOM_FIELD), which would re-evaluate every
        // create-custom-field rule on this lead from inside the workflow that is already running.
        // The finally matters: $enableWorkflows is per-instance and this same Lead object is handed
        // to ChangeTaskEngagementItemStatusAction next, which does want its workflows.
        $lead->disableWorkflows();

        try {
            if ($stored === []) {
                // Must be del(), never set([]): get() starts with `if ($value = getFromRedis())`,
                // and an empty array is falsy, so a stored [] falls through to the stale DB value.
                $lead->del(self::CUSTOM_FIELD);
            } else {
                $lead->set(self::CUSTOM_FIELD, $stored);
            }
        } finally {
            $lead->enableWorkflows();
        }

        return [$entries, true];
    }

    /**
     * @return list<ChecklistPdfEntry>
     */
    private function readEntries(Lead $lead): array
    {
        return array_values(array_filter(array_map(
            ChecklistPdfEntry::fromArray(...),
            (array) $lead->get(self::CUSTOM_FIELD, [])
        )));
    }

    /**
     * @param list<ChecklistPdfEntry> $entries
     *
     * @return list<array{action_id: int, company_action_id: int, task_id: int, status: string}>
     */
    private static function toStoredArray(array $entries): array
    {
        return array_map(fn (ChecklistPdfEntry $entry): array => $entry->toArray(), $entries);
    }
}
