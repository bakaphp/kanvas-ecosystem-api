<?php

declare(strict_types=1);

namespace Kanvas\ActionEngine\Tasks\Support;

use Kanvas\ActionEngine\Tasks\Enums\ChecklistPdfGenerationEnum;

/**
 * One in-flight PDF generation, as stored in the lead's `checklist.generate.pdf` custom field.
 */
readonly class ChecklistPdfEntry
{
    public function __construct(
        public int $actionId,
        public int $companyActionId,
        public int $taskId,
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
            status: $status
        );
    }

    /**
     * @return array{action_id: int, company_action_id: int, task_id: int, status: string}
     */
    public function toArray(): array
    {
        return [
            'action_id' => $this->actionId,
            'company_action_id' => $this->companyActionId,
            'task_id' => $this->taskId,
            'status' => $this->status->value,
        ];
    }
}
