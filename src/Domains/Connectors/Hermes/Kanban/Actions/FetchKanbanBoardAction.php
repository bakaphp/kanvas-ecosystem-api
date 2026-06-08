<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Hermes\Kanban\Actions;

use Kanvas\Connectors\Hermes\Kanban\Traits\InteractsWithHermesKanbanCli;
use Kanvas\Connectors\Hermes\Services\HermesContainerCliService;
use Kanvas\Intelligence\AgentRuntime\DataTransferObject\KanbanTask;
use Kanvas\Intelligence\AgentRuntime\Enums\KanbanStatusEnum;
use Kanvas\Intelligence\Agents\Models\AgentDeployment;

/**
 * Discovers an agent's *active* cards. `list --json` rows are flat (no edges), so we `show --json <id>`
 * per active row to populate the tree + handoff. Terminal rows (done/archived) are skipped here — they
 * either are already tracked (refreshed by id in SyncDeploymentKanbanAction) or are a rare new-and-done
 * card we accept missing; this keeps discovery bounded to active work rather than all board history.
 */
class FetchKanbanBoardAction
{
    use InteractsWithHermesKanbanCli;

    public function __construct(
        private readonly AgentDeployment $deployment,
        private readonly ?string $assignee = null,
        private readonly ?string $tenant = null,
        private readonly ?string $board = null,
    ) {
    }

    /**
     * @return list<KanbanTask>
     */
    public function execute(): array
    {
        return $this->withCli(function (HermesContainerCliService $cli): array {
            $listArgs = ['list'];

            if ($this->assignee !== null && $this->assignee !== '') {
                $listArgs[] = '--assignee';
                $listArgs[] = $this->assignee;
            }

            if ($this->tenant !== null && $this->tenant !== '') {
                $listArgs[] = '--tenant';
                $listArgs[] = $this->tenant;
            }

            $tasks = [];

            foreach ($cli->runJson($this->kanbanArgs($listArgs)) as $row) {
                if (! is_array($row) || ! isset($row['id'])) {
                    continue;
                }

                $status = KanbanStatusEnum::fromRuntime(isset($row['status']) ? (string) $row['status'] : null);
                if ($status === KanbanStatusEnum::DONE || $status === KanbanStatusEnum::ARCHIVED) {
                    continue;
                }

                $tasks[] = KanbanTask::parseShowPayload(
                    $cli->runJson($this->kanbanArgs(['show', (string) $row['id']])),
                );
            }

            return $tasks;
        });
    }
}
