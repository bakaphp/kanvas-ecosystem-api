<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Claude;

use Kanvas\Connectors\ClaudeAgent\Actions\DispatchLongTaskAction;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Users\Models\Users;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use Override;
use Throwable;

/**
 * Hands work to a hosted Claude Task Agent and returns immediately.
 *
 * This is what makes the async path reachable: `DispatchLongTaskAction` otherwise has no caller, so
 * a Task Agent can be created but never given anything to do. The tool goes on the agent that
 * *delegates* (a PM, a coordinator), not on the Task Agent itself.
 *
 * Returns a task id and nothing else on purpose — the work has only started. The board and the
 * plan feed carry the result; a tool that waited would block the turn for hours.
 */
#[AgentTool(name: 'Dispatch Long Task', category: 'claude')]
class DispatchLongTaskTool extends Tool
{
    public function __construct(
        private readonly Agent $executor,
        private readonly ?Users $requestedBy = null,
    ) {
        parent::__construct(
            name: 'dispatch_long_task',
            description: 'Hand a large, self-contained piece of work to a hosted Claude agent that runs '
                . 'in its own sandbox — multi-file code changes, data migrations, audits over many records, '
                . 'or generated documents. Use this when the work would take more than a couple of minutes '
                . 'or produces a file rather than an answer. It returns a task id IMMEDIATELY; the work is '
                . 'NOT done when this returns. Do not use it for questions you can answer directly.',
        );
    }

    /**
     * @return array<int, ToolProperty>
     */
    #[Override]
    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'brief',
                type: PropertyType::STRING,
                description: 'The complete instruction. The hosted agent CANNOT ask follow-up questions, '
                    . 'so include everything it needs: what to change, where, acceptance criteria, and any '
                    . 'constraints. Ambiguity produces the wrong result.',
                required: true,
            ),
            new ToolProperty(
                name: 'rubric',
                type: PropertyType::STRING,
                description: 'Optional. Explicit, checkable criteria for "done" — the platform will grade '
                    . 'the work against these and iterate until they pass. Criteria must be verifiable INSIDE '
                    . 'a sandbox with no database: "the CSV has a numeric price column" works, "the tests pass" '
                    . 'does not.',
                required: false,
            ),
            new ToolProperty(
                name: 'repo_slug',
                type: PropertyType::STRING,
                description: 'Optional. Restrict the work to one repository from the agent\'s allow-list, '
                    . 'by slug. Omit to make every allowed repository available.',
                required: false,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(string $brief, ?string $rubric = null, ?string $repo_slug = null): array
    {
        try {
            $task = new DispatchLongTaskAction(
                agent: $this->executor,
                brief: $brief,
                requestedBy: $this->requestedBy,
                rubric: $rubric,
                repoSlugs: $repo_slug !== null && $repo_slug !== '' ? [$repo_slug] : [],
            )->execute();
        } catch (Throwable $e) {
            return ['status' => 'error', 'message' => $e->getMessage()];
        }

        return [
            'status' => 'dispatched',
            'task_id' => $task->getId(),
            'plan_id' => $task->plan?->getId(),
            'note' => 'The work has STARTED, not finished. Do not report it as complete. '
                . 'Check back on a later turn — progress appears on the plan and the task status changes '
                . 'when it is genuinely done.',
        ];
    }
}
