<?php

declare(strict_types=1);

namespace Kanvas\ActionEngine\Tasks\Support;

use Kanvas\ActionEngine\Tasks\Enums\ChecklistPdfGenerationEnum;

readonly class ChecklistPdfEntry
{
    /**
     * `messageId` rather than the integration history id, which executeIntegration only writes after
     * the closure marks the failure; clients look the history row up by message to retry it.
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
     * Null for a row whose task or status can't be read, so the next write drops it.
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
