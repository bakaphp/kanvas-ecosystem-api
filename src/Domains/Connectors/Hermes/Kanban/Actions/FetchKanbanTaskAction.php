<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Hermes\Kanban\Actions;

use Kanvas\Connectors\Hermes\Kanban\Traits\InteractsWithHermesKanbanCli;
use Kanvas\Connectors\Hermes\Services\HermesContainerCliService;
use Kanvas\Intelligence\AgentRuntime\DataTransferObject\KanbanTask;
use Kanvas\Intelligence\Agents\Models\AgentDeployment;
use Throwable;

/**
 * Fetch a single card by id — `kanban show <id> --json` — assignee-agnostic. Used to refresh a
 * card we already track by `AGENT_KANBAN_TASK_ID` regardless of who it's now assigned to (a card
 * reassigned away from the agent's profile would otherwise fall out of the board-list slice).
 * Returns null if the card no longer exists.
 */
class FetchKanbanTaskAction
{
    use InteractsWithHermesKanbanCli;

    public function __construct(
        private readonly AgentDeployment $deployment,
        private readonly string $externalTaskId,
        private readonly ?string $board = null,
    ) {
    }

    public function execute(): ?KanbanTask
    {
        return $this->withCli(function (HermesContainerCliService $cli): ?KanbanTask {
            try {
                $payload = $cli->runJson($this->kanbanArgs(['show', $this->externalTaskId]));
            } catch (Throwable) {
                return null;
            }

            $task = $payload['task'] ?? null;

            if (! is_array($task) || ! isset($task['id'])) {
                return null;
            }

            return KanbanTask::parseShowPayload($payload);
        });
    }
}
