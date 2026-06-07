<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Hermes\Kanban\Actions;

use Kanvas\Connectors\Hermes\Kanban\Traits\InteractsWithHermesKanbanCli;
use Kanvas\Intelligence\AgentRuntime\DataTransferObject\KanbanTask;
use Kanvas\Intelligence\Agents\Models\AgentDeployment;
use Kanvas\Intelligence\Agents\Models\AgentMachine;

/**
 * Reads an agent's slice of the Hermes board.
 *
 * `list --json` rows are flat (no edges, no idempotency_key) and hide archived tasks, so we
 * pass `--archived` to enumerate, then `show --json <id>` per task to populate the tree
 * (parents/children) and the worker handoff (latest_summary + runs metadata). N+1 per slice —
 * acceptable for v1; optimise later with a bulk task_links read.
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
        $machine = $this->deployment->machine;

        if (! $machine instanceof AgentMachine) {
            return [];
        }

        $ssh = $this->openSshClient($machine);

        try {
            $cli = $this->cli($ssh);

            $listArgs = ['list', '--archived'];

            if ($this->assignee !== null && $this->assignee !== '') {
                $listArgs[] = '--assignee';
                $listArgs[] = $this->assignee;
            }

            if ($this->tenant !== null && $this->tenant !== '') {
                $listArgs[] = '--tenant';
                $listArgs[] = $this->tenant;
            }

            $rows = $cli->runJson($this->kanbanArgs($listArgs));

            $tasks = [];

            foreach ($rows as $row) {
                if (! is_array($row) || ! isset($row['id'])) {
                    continue;
                }

                $show = $cli->runJson($this->kanbanArgs(['show', (string) $row['id']]));
                $tasks[] = KanbanTask::parseShowPayload($show);
            }

            return $tasks;
        } finally {
            $ssh->disconnect();
        }
    }
}
