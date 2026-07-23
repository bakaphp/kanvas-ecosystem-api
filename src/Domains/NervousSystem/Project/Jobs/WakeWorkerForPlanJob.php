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
use Kanvas\Intelligence\Agents\Neuron\Tools\NervousSystem\AddNervousSystemTaskTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\NervousSystem\CommentOnNervousSystemPlanTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\NervousSystem\DeleteNervousSystemTaskTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\NervousSystem\UpdateNervousSystemPlanTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\NervousSystem\UpdateNervousSystemTaskStatusTool;
use Kanvas\Intelligence\Sessions\Models\Session;
use Kanvas\NervousSystem\Ledger\Enums\EventStatusEnum;
use Kanvas\NervousSystem\Plan\Actions\PostPlanActivityMessageAction;
use Kanvas\NervousSystem\Plan\Models\Plan;
use Throwable;

/**
 * Wake the agent a PLAN was assigned to, so it OWNS and executes that plan — the "agents work plans,
 * not one-off tasks" model. Unlike a bare task wake, the worker is handed a scoped board toolset at
 * wake time (add subtasks, move their status, comment, complete the plan) so it can actually decompose
 * and finish the work. Its own persona doesn't need those tools granted — they're injected per-run.
 */
class WakeWorkerForPlanJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use KanvasJobsTrait;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly Plan $plan,
    ) {
        $this->onQueue('nervous-system-project');
    }

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [
            new WithoutOverlapping('plan-worker-' . $this->plan->getId())->dontRelease(),
        ];
    }

    public function handle(): void
    {
        $agent = $this->plan->agent;
        if ($agent === null) {
            return;
        }

        // Only run agents that can actually execute board work: active, in-process, tool-capable
        // Neuron agents. Container/ADK self-drive elsewhere; CRM/Lead-context agents would fatal on a
        // Plan/Task entity. A non-executor should never have been assigned (assign_plan guards it) —
        // this is the safety net.
        if (! $agent->is_active || ! $agent->canExecuteBoardWork()) {
            return;
        }

        $this->overwriteAppService($agent->app);

        $owner = $agent->user;
        if ($owner === null) {
            return;
        }

        $session = $this->resolveSession();
        $startedAt = microtime(true);

        // Board tools scoped to this worker's context — injected only for this run.
        $tools = [
            new AddNervousSystemTaskTool()->withContext($agent->app, $agent->company, $owner),
            new UpdateNervousSystemTaskStatusTool()->withContext($agent->app, $agent->company, $owner),
            new DeleteNervousSystemTaskTool()->withContext($agent->app, $agent->company, $owner),
            new UpdateNervousSystemPlanTool()->withContext($agent->app, $agent->company, $owner),
            new CommentOnNervousSystemPlanTool()->withContext($agent->app, $agent->company, $owner),
        ];

        try {
            $response = new AgentChatKernel(
                agent: $agent,
                session: $session,
                message: $this->buildMessage(),
                user: $owner,
                additionalTools: $tools,
                // Reply is posted explicitly below; don't persist the scaffolded prompt (avoids the
                // re-ingested-prompt growth loop — see WakeAgentForProjectJob).
                persistConversation: false,
            )->execute();
        } catch (Throwable $e) {
            $this->plan->emitLedgerEvent(
                eventType: 'plan.worker.invocation_failed',
                status: EventStatusEnum::ERROR,
                payload: ['plan_id' => $this->plan->getId(), 'agent_id' => $agent->getId()],
                error: ['message' => $e->getMessage(), 'class' => $e::class],
                durationMs: (int) ((microtime(true) - $startedAt) * 1000),
            );

            throw $e;
        }

        $this->plan->emitLedgerEvent(
            'plan.worker.invoked',
            payload: ['plan_id' => $this->plan->getId(), 'agent_id' => $agent->getId()],
            durationMs: (int) ((microtime(true) - $startedAt) * 1000),
        );

        $reply = new PostPlanActivityMessageAction(
            $this->plan,
            $response,
            author: $owner,
        )->execute();

        $this->plan->emitLedgerEvent(
            'plan.worker.replied',
            payload: ['plan_id' => $this->plan->getId(), 'message_id' => $reply?->getId()],
        );
    }

    protected function resolveSession(): Session
    {
        /** @var Session $session */
        $session = Session::firstOrCreate(
            [
                'apps_id' => $this->plan->apps_id,
                'companies_id' => $this->plan->companies_id,
                'entity_namespace' => Plan::class,
                'entity_id' => $this->plan->getId(),
            ],
            [
                'uuid' => Str::uuid()->toString(),
                'agents_id' => $this->plan->agent_id,
                'channel_id' => null,
                'content' => '',
                'user' => [],
            ],
        );

        return $session;
    }

    protected function buildMessage(): string
    {
        return sprintf(
            "[NS:plan_assigned plan_id=%d]\n\n"
            . 'You own this plan. Break it into concrete subtasks with add_nervous_system_task, do the '
            . 'work, move each subtask with update_nervous_system_task_status (in_progress -> done, or '
            . 'blocked with a reason), and post progress with comment_on_nervous_system_plan. When every '
            . "subtask is done, mark the plan done with update_nervous_system_plan (status=done).\n\n"
            . "Plan: %s\n%s",
            $this->plan->getId(),
            $this->plan->title,
            $this->plan->description !== null && $this->plan->description !== ''
                ? "Details: {$this->plan->description}\n"
                : '',
        );
    }
}
