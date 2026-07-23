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
use Illuminate\Support\Str;
use Kanvas\Intelligence\Agents\Actions\Chat\AgentChatKernel;
use Kanvas\Intelligence\Sessions\Models\Session;
use Kanvas\NervousSystem\Ledger\Enums\EventStatusEnum;
use Kanvas\NervousSystem\Plan\Models\Task;
use Kanvas\NervousSystem\Project\Actions\PostProjectMessageAction;
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

        // Only active, in-process, tool-capable Neuron agents can execute board work. Container/ADK
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

        $session = $this->resolveSession();
        $startedAt = microtime(true);

        try {
            $response = new AgentChatKernel(
                agent: $agent,
                session: $session,
                message: $this->buildMessage($project),
                user: $owner,
                // Reply is posted explicitly below; don't persist the scaffolded prompt (avoids the
                // re-ingested-prompt growth loop — see WakeAgentForProjectJob).
                persistConversation: false,
            )->execute();
        } catch (Throwable $e) {
            $project->emitLedgerEvent(
                eventType: 'task.agent.invocation_failed',
                status: EventStatusEnum::ERROR,
                payload: ['task_id' => $this->task->getId(), 'agent_id' => $agent->getId()],
                error: ['message' => $e->getMessage(), 'class' => $e::class],
                durationMs: (int) ((microtime(true) - $startedAt) * 1000),
            );

            throw $e;
        }

        $project->emitLedgerEvent(
            'task.agent.invoked',
            payload: ['task_id' => $this->task->getId(), 'agent_id' => $agent->getId()],
            durationMs: (int) ((microtime(true) - $startedAt) * 1000),
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

    protected function resolveSession(): Session
    {
        /** @var Session $session */
        $session = Session::firstOrCreate(
            [
                'apps_id' => $this->task->apps_id,
                'companies_id' => $this->task->companies_id,
                'entity_namespace' => Task::class,
                'entity_id' => $this->task->getId(),
            ],
            [
                'uuid' => Str::uuid()->toString(),
                'agents_id' => $this->task->agent_id,
                'channel_id' => null,
                'content' => '',
                'user' => [],
            ],
        );

        return $session;
    }

    protected function buildMessage(Project $project): string
    {
        $plan = $this->task->plan;

        return sprintf(
            "[NS:task_assigned task_id=%d]\n\n"
            . 'You have been assigned this task. Do the work, then report clearly what you did (and if '
            . "you have the tool to, set the task status to done or blocked with a reason).\n\n"
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
