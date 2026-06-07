<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Hermes\Kanban\Actions;

use Kanvas\Connectors\Hermes\Kanban\Traits\InteractsWithHermesKanbanCli;
use Kanvas\Connectors\Hermes\Services\HermesContainerCliService;
use Kanvas\Intelligence\AgentRuntime\DataTransferObject\KanbanTask;
use Kanvas\Intelligence\AgentRuntime\Enums\KanbanTransition;
use Kanvas\Intelligence\Agents\Models\AgentDeployment;

/**
 * Applies a lifecycle verb to a Hermes task, then re-reads it with `show --json`.
 *
 * The verbs print plain text (not JSON) and the runtime enforces a state machine — an illegal
 * verb (e.g. `complete` on a `todo` task) is rejected. We don't trust the verb's stdout; we
 * re-fetch and return the ACTUAL resulting task so the caller can confirm whether the move took
 * (compare returned status to the intended one). Gate legality upstream via canTransition().
 */
class TransitionKanbanTaskAction
{
    use InteractsWithHermesKanbanCli;

    public function __construct(
        private readonly AgentDeployment $deployment,
        private readonly string $externalTaskId,
        private readonly KanbanTransition $transition,
        private readonly ?string $reason = null,
        private readonly ?string $assignee = null,
        private readonly ?string $result = null,
        private readonly ?string $board = null,
    ) {
    }

    public function execute(): KanbanTask
    {
        return $this->withCli(function (HermesContainerCliService $cli): KanbanTask {
            $cli->run($this->kanbanArgs($this->buildArgs()));

            return KanbanTask::parseShowPayload($cli->runJson($this->kanbanArgs(['show', $this->externalTaskId])));
        });
    }

    /**
     * @return list<string>
     */
    private function buildArgs(): array
    {
        return match ($this->transition) {
            KanbanTransition::COMPLETE => $this->result !== null && $this->result !== ''
                ? ['complete', $this->externalTaskId, '--result', $this->result]
                : ['complete', $this->externalTaskId],
            KanbanTransition::BLOCK => ['block', $this->externalTaskId, $this->reason ?? 'Blocked from Kanvas'],
            KanbanTransition::UNBLOCK => ['unblock', $this->externalTaskId],
            KanbanTransition::ARCHIVE => ['archive', $this->externalTaskId],
            KanbanTransition::ASSIGN => ['assign', $this->externalTaskId, $this->assignee ?? ''],
        };
    }
}
