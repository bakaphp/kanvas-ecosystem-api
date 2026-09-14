<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Project\Jobs;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Kanvas\Connectors\ClaudeAgent\Actions\StartHostedTaskSessionAction;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Sessions\Models\Session;
use Kanvas\NervousSystem\Ledger\Enums\EventStatusEnum;
use Kanvas\NervousSystem\Plan\Models\Task;
use Kanvas\NervousSystem\Project\Actions\PostProjectMessageAction;
use Kanvas\NervousSystem\Project\Jobs\Traits\DrivesAgentWake;
use Kanvas\NervousSystem\Project\Models\Project;
use Throwable;

/**
 * Wake the agent a task was ASSIGNED to, so it actually executes the work — the "and agents execute"
 * half of delegation. Dispatched by the assign-task tool. The assignee reads the task (with its plan
 * + project objective), does the work through the LLM, and reports on the project channel. Its own
 * task-status update depends on the tools it carries; the PM follows up on the next tick otherwise.
 */
class WakeAgentForTaskJob implements ShouldQueue
{
    use Dispatchable;
    use DrivesAgentWake;
    use InteractsWithQueue;
    use KanvasJobsTrait;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly Task $task,
    ) {
        $this->onQueue('nervous-system-project');
    }

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [
            new WithoutOverlapping('task-wake-' . $this->task->getId())->dontRelease(),
        ];
    }

    public function handle(): void
    {
        $agent = $this->task->agent;
        $plan = $this->task->plan;

        if ($agent === null || $plan === null || $plan->project_id === null) {
            return;
        }

        // Only active, tool-capable agents can execute board work. Machine runtimes (Hermes/OpenClaw)
        // self-drive elsewhere; CRM/Lead-context agents would fatal on a Task entity. assign_task
        // guards this; this is the safety net.
        if (! $agent->is_active || ! $agent->canExecuteBoardWork()) {
            return;
        }

        $this->overwriteAppService($agent->app);

        $project = Project::query()->where('id', $plan->project_id)->notDeleted()->first();
        if ($project === null) {
            return;
        }

        $owner = $agent->user;
        if ($owner === null) {
            return;
        }

        if ($agent->isHostedRuntime()) {
            $this->startHostedRun($agent, $project);

            return;
        }

        $session = $this->resolveSession();
        $basePayload = ['task_id' => $this->task->getId(), 'agent_id' => $agent->getId()];

        [$response, $durationMs] = $this->runAgentWake(
            $agent,
            $session,
            $owner,
            $this->buildMessage($project),
            $project,
            'task.agent',
            $basePayload,
        );

        $project->emitLedgerEvent(
            'task.agent.invoked',
            payload: $basePayload,
            durationMs: $durationMs,
        );

        $reply = new PostProjectMessageAction(
            project: $project,
            verb: 'task-agent-reply',
            content: $response,
            author: $owner,
            fromIa: true,
            extraPayload: ['task_id' => $this->task->getId()],
        )->execute();

        $project->emitLedgerEvent(
            'task.agent.replied',
            payload: ['task_id' => $this->task->getId(), 'message_id' => $reply->getId()],
        );
    }

    /**
     * A hosted assignee runs asynchronously: open its session, say so, and return. Driving it
     * through the synchronous wake would block a worker for the length of the whole job — a queue
     * worker caps out around an hour and these can run longer.
     *
     * The reply is deliberately worded as a START. The task stays `in_progress` and only the poller
     * writes a terminal status; a wake that read as completion would have the PM report the work
     * done the moment it was handed over.
     */
    protected function startHostedRun(Agent $agent, Project $project): void
    {
        $basePayload = ['task_id' => $this->task->getId(), 'agent_id' => $agent->getId()];

        try {
            $sessionId = new StartHostedTaskSessionAction(
                task: $this->task,
                agent: $agent,
                brief: $this->buildMessage($project),
            )->execute();
        } catch (Throwable $e) {
            $project->emitLedgerEvent(
                'task.agent.invocation_failed',
                status: EventStatusEnum::ERROR,
                payload: $basePayload,
                error: ['message' => $e->getMessage(), 'class' => $e::class],
            );

            return;
        }

        $project->emitLedgerEvent(
            'task.agent.dispatched',
            payload: [...$basePayload, 'session_id' => $sessionId],
        );

        $reply = new PostProjectMessageAction(
            project: $project,
            verb: 'task-agent-started',
            content: sprintf(
                'Started work on task %d. This runs in the background and is NOT finished yet — '
                . 'progress appears on the plan and the task status changes when it is genuinely done.',
                $this->task->getId(),
            ),
            author: $agent->user,
            fromIa: true,
            extraPayload: [...$basePayload, 'session_id' => $sessionId],
        )->execute();

        $project->emitLedgerEvent(
            'task.agent.replied',
            payload: ['task_id' => $this->task->getId(), 'message_id' => $reply->getId()],
        );
    }

    protected function resolveSession(): Session
    {
        return $this->firstOrCreateWakeSession(
            $this->task,
            create: ['agents_id' => $this->task->agent_id],
        );
    }

    protected function buildMessage(Project $project): string
    {
        $plan = $this->task->plan;

        return sprintf(
            "[NS:task_assigned task_id=%d]\n\n"
            . 'You have been assigned this task. FIRST check your capability: you can only do what your '
            . 'available tools allow. If this task needs an ability you have no tool for (writing/deploying '
            . 'code, changing a database, sending email, calling an external system, files/servers), DO NOT '
            . 'pretend to do it or mark it done — set the task status to blocked with a clear reason naming '
            . 'the missing capability so the PM can reassign it. Otherwise do the work and set the task done. '
            . "Only mark done what you ACTUALLY completed with your tools.\n\n"
            . "Task: %s\n%s\nPlan: %s\nProject objective: %s\n",
            $this->task->getId(),
            $this->task->title,
            $this->task->description !== null && $this->task->description !== ''
                ? "Details: {$this->task->description}\n"
                : '',
            $plan?->title ?? '',
            $project->objective ?? '(not set)',
        );
    }
}
