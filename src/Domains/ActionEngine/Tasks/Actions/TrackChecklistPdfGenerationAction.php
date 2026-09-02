<?php

declare(strict_types=1);

namespace Kanvas\ActionEngine\Tasks\Actions;

use Illuminate\Support\Facades\Cache;
use Kanvas\ActionEngine\Tasks\Enums\ChecklistPdfGenerationEnum;
use Kanvas\ActionEngine\Tasks\Events\ChecklistGeneratePdfEvent;
use Kanvas\ActionEngine\Tasks\Support\ChecklistPdfContext;
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
     * @return array<int, array{action_id: int, company_action_id: int, task_id: int, status: string}>
     */
    public function execute(): array
    {
        /** @var Lead $lead */
        $lead = $this->context->engagement->lead;
        $taskId = $this->context->taskListItem->getId();

        // Keyed by lead, not by task: a task finishing must not del() the field out from under
        // another task on the same lead that just started generating.
        $lockKey = 'checklist_generate_pdf:' . $lead->getId();

        return Cache::lock($lockKey, 10)->block(10, function () use ($lead, $taskId): array {
            $entries = $this->writeEntries($lead, $taskId);

            ChecklistGeneratePdfEvent::dispatch(
                $lead->getId(),
                (string) $lead->uuid,
                $entries
            );

            return $entries;
        });
    }

    /**
     * @return array<int, array{action_id: int, company_action_id: int, task_id: int, status: string}>
     */
    private function writeEntries(Lead $lead, int $taskId): array
    {
        $entries = array_values(array_filter(
            (array) $lead->get(self::CUSTOM_FIELD, []),
            fn (array $entry): bool => (int) ($entry['task_id'] ?? 0) !== $taskId
        ));

        if ($this->status !== null) {
            $companyAction = $this->context->taskListItem->companyAction;

            $entries[] = [
                'action_id' => (int) $companyAction->actions_id,
                'company_action_id' => $companyAction->getId(),
                'task_id' => $taskId,
                'status' => $this->status->value,
            ];
        }

        // set() ends in fireWorkflow(CREATE_CUSTOM_FIELD), which would re-evaluate every
        // create-custom-field rule on this lead from inside the workflow that is already running.
        // The finally matters: $enableWorkflows is per-instance and this same Lead object is handed
        // to ChangeTaskEngagementItemStatusAction next, which does want its workflows.
        $lead->disableWorkflows();

        try {
            if ($entries === []) {
                // Must be del(), never set([]): get() starts with `if ($value = getFromRedis())`,
                // and an empty array is falsy, so a stored [] falls through to the stale DB value.
                $lead->del(self::CUSTOM_FIELD);
            } else {
                $lead->set(self::CUSTOM_FIELD, $entries);
            }
        } finally {
            $lead->enableWorkflows();
        }

        return $entries;
    }
}
