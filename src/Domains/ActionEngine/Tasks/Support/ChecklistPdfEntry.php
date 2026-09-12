<?php

declare(strict_types=1);

namespace Kanvas\ActionEngine\Tasks\Support;

use Kanvas\ActionEngine\Tasks\Enums\ChecklistPdfGenerationEnum;

/**
 * One in-flight PDF generation, as stored in the lead's `checklist.generate.pdf` custom field.
 */
readonly class ChecklistPdfEntry
{
    /**
     * `messageId` is what lets a client turn a failed entry into a retry: it finds that message's
     * row in `entity_integration_history` and hands the id to `integrationWorkflowRetry`. The
     * activity cannot carry the history id itself — executeIntegration writes that row after the
     * closure it marks the failure in.
     */
    public function __construct(
        public int $actionId,
        public int $companyActionId,
        public int $taskId,
        public int $messageId,
        public ChecklistPdfGenerationEnum $status
    ) {
    }

    /**
     * Null for anything this class did not write — a row whose task or status can't be read is one
     * the client can't match to a checklist item either, so it is dropped rather than kept forever.
     */
    public static function fromArray(mixed $entry): ?self
    {
        if (! is_array($entry)) {
            return null;
        }

        $status = ChecklistPdfGenerationEnum::tryFrom((string) ($entry['status'] ?? ''));
        $taskId = (int) ($entry['task_id'] ?? 0);

        if ($status === null || $taskId === 0) {
            return null;
        }

        return new self(
            actionId: (int) ($entry['action_id'] ?? 0),
            companyActionId: (int) ($entry['company_action_id'] ?? 0),
            taskId: $taskId,
            // Zero rather than invalid: an entry without it is still matchable by task, it just
            // cannot be retried.
            messageId: (int) ($entry['message_id'] ?? 0),
            status: $status
        );
    }

    /**
     * @return array{action_id: int, company_action_id: int, task_id: int, message_id: int, status: string}
     */
    public function toArray(): array
    {
        return [
            'action_id' => $this->actionId,
            'company_action_id' => $this->companyActionId,
            'task_id' => $this->taskId,
            'message_id' => $this->messageId,
            'status' => $this->status->value,
        ];
    }
}
