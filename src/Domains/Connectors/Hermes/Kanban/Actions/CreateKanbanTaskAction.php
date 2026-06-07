<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Hermes\Kanban\Actions;

use Kanvas\Connectors\Hermes\Kanban\Traits\InteractsWithHermesKanbanCli;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\AgentRuntime\DataTransferObject\KanbanTask;
use Kanvas\Intelligence\AgentRuntime\DataTransferObject\KanbanTaskInput;
use Kanvas\Intelligence\Agents\Models\AgentDeployment;
use Kanvas\Intelligence\Agents\Models\AgentMachine;

/**
 * Creates a task on the Hermes board: `kanban create "<title>" --json …`.
 *
 * `--idempotency-key` (the Kanvas uuid) makes the create dedup — re-running returns the
 * existing id. No `--parent` = a root task (→ Kanvas Plan); one or more = a child (→ Task).
 * Never passes `--triage` — that would trigger Hermes auto-decompose and rewrite the task.
 */
class CreateKanbanTaskAction
{
    use InteractsWithHermesKanbanCli;

    public function __construct(
        private readonly AgentDeployment $deployment,
        private readonly KanbanTaskInput $input,
        private readonly ?string $board = null,
    ) {
    }

    public function execute(): KanbanTask
    {
        $machine = $this->deployment->machine;

        if (! $machine instanceof AgentMachine) {
            throw new ValidationException('Agent deployment has no machine to create a kanban task on');
        }

        $ssh = $this->openSshClient($machine);

        try {
            $row = $this->cli($ssh)->runJson($this->kanbanArgs($this->buildArgs()));

            return KanbanTask::parseFlatRow($row);
        } finally {
            $ssh->disconnect();
        }
    }

    /**
     * @return list<string>
     */
    private function buildArgs(): array
    {
        $args = ['create', $this->input->title];

        if ($this->input->assignee !== null && $this->input->assignee !== '') {
            $args[] = '--assignee';
            $args[] = $this->input->assignee;
        }

        if ($this->input->idempotencyKey !== null && $this->input->idempotencyKey !== '') {
            $args[] = '--idempotency-key';
            $args[] = $this->input->idempotencyKey;
        }

        foreach ($this->input->parentIds as $parentId) {
            $args[] = '--parent';
            $args[] = $parentId;
        }

        if ($this->input->body !== null && $this->input->body !== '') {
            $args[] = '--body';
            $args[] = $this->input->body;
        }

        if ($this->input->priority !== null) {
            $args[] = '--priority';
            $args[] = (string) $this->input->priority;
        }

        if ($this->input->tenant !== null && $this->input->tenant !== '') {
            $args[] = '--tenant';
            $args[] = $this->input->tenant;
        }

        if ($this->input->workspace !== null && $this->input->workspace !== '') {
            $args[] = '--workspace';
            $args[] = $this->input->workspace;
        }

        return $args;
    }
}
