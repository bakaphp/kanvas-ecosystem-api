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
use Kanvas\Intelligence\Agents\Neuron\Tools\NervousSystem\AddNervousSystemTaskTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\NervousSystem\CommentOnNervousSystemPlanTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\NervousSystem\DeleteNervousSystemTaskTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\NervousSystem\UpdateNervousSystemPlanTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\NervousSystem\UpdateNervousSystemTaskStatusTool;
use Kanvas\Intelligence\Sessions\Models\Session;
use Kanvas\NervousSystem\Plan\Actions\PostPlanActivityMessageAction;
use Kanvas\NervousSystem\Plan\Enums\PlanStatusEnum;
use Kanvas\NervousSystem\Plan\Models\Plan;
use Kanvas\NervousSystem\Project\Jobs\Traits\DrivesAgentWake;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Users\Models\Users;

/**
 * Wake the agent a PLAN was assigned to, so it OWNS and executes that plan — the "agents work plans,
 * not one-off tasks" model. Unlike a bare task wake, the worker is handed a scoped board toolset at
 * wake time (add subtasks, move their status, comment, complete the plan) so it can actually decompose
 * and finish the work. Its own persona doesn't need those tools granted — they're injected per-run.
 */
class WakeWorkerForPlanJob implements ShouldQueue
{
    use Dispatchable;
    use DrivesAgentWake;
    use InteractsWithQueue;
    use KanvasJobsTrait;
    use Queueable;
    use SerializesModels;

    private const int ACTIVITY_LOOKBACK = 6;

    // Memoized so the before/after comment-count baseline reads the same channel object twice.
    private ?Channel $channel = null;
    private bool $channelResolved = false;

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

        // Skip the re-block spam: if the plan is already blocked and the last note on it is this
        // worker's OWN, nothing changed since it blocked. A newer note from someone else (PM/human) is
        // what re-invites a run.
        if ($this->plan->status === PlanStatusEnum::BLOCKED->value && $this->lastNoteIsFrom($owner)) {
            $this->plan->emitLedgerEvent(
                'plan.worker.skipped',
                payload: [
                    'plan_id' => $this->plan->getId(),
                    'agent_id' => $agent->getId(),
                    'reason' => 'blocked_no_change',
                ],
            );

            return;
        }

        $session = $this->resolveSession();

        // Baseline the plan's activity channel so we can tell if the worker posts a comment during
        // the run (via comment_on_nervous_system_plan) — if it does, its final reply is a duplicate.
        $channel = $this->planChannel();
        $activityBefore = $channel?->messages()->count() ?? 0;

        // Board tools scoped to this worker's context — injected only for this run.
        $tools = [
            new AddNervousSystemTaskTool()->withContext($agent->app, $agent->company, $owner),
            new UpdateNervousSystemTaskStatusTool()->withContext($agent->app, $agent->company, $owner),
            new DeleteNervousSystemTaskTool()->withContext($agent->app, $agent->company, $owner),
            new UpdateNervousSystemPlanTool()->withContext($agent->app, $agent->company, $owner),
            new CommentOnNervousSystemPlanTool()->withContext($agent->app, $agent->company, $owner),
        ];

        $basePayload = ['plan_id' => $this->plan->getId(), 'agent_id' => $agent->getId()];

        [$response, $durationMs] = $this->runAgentWake(
            $agent,
            $session,
            $owner,
            $this->buildMessage(),
            $this->plan,
            'plan.worker',
            $basePayload,
            $tools,
        );

        $this->plan->emitLedgerEvent(
            'plan.worker.invoked',
            payload: $basePayload,
            durationMs: $durationMs,
        );

        // Only post the final reply if the worker didn't already comment during the run — otherwise
        // the reply just duplicates the comment it already left on the plan.
        $postedDuringRun = $channel !== null && $channel->messages()->count() > $activityBefore;

        if (! $postedDuringRun) {
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
    }

    protected function resolveSession(): Session
    {
        // Key the session on (plan, AGENT), not the plan alone — otherwise a plan reassigned to a new
        // agent reuses the predecessor's session and inherits its "I'm blocked, I lack the tools"
        // thread, so the new agent just re-asserts the block. Per-agent sessions start each assignee
        // fresh (root cause of the NS-6909 re-block loop).
        return $this->firstOrCreateWakeSession(
            $this->plan,
            extraKey: ['agents_id' => $this->plan->agent_id],
        );
    }

    protected function buildMessage(): string
    {
        return sprintf(
            "[NS:plan_assigned plan_id=%d status=%s]\n\n"
            . 'You own this plan. Break it into concrete subtasks with add_nervous_system_task, do the '
            . 'work, move each subtask with update_nervous_system_task_status (in_progress -> done, or '
            . 'blocked with a reason), and post progress with comment_on_nervous_system_plan. When every '
            . 'subtask is done, mark the plan done with update_nervous_system_plan (status=done). Add each '
            . 'distinct subtask EXACTLY ONCE — if a tool result says reused/already exists, do NOT call it '
            . "again, move on to the next one or finish.\n\n"
            . 'FIRST, CHECK YOUR CAPABILITY. You can ONLY do what your available tools let you do. Look at '
            . "what this plan actually requires. If it needs abilities you don't have a tool for (writing "
            . 'or deploying code, changing a database/schema, sending email, calling an external system, '
            . 'accessing files/servers, etc.), DO NOT pretend to do it and DO NOT mark it done. Instead set '
            . 'the plan blocked with update_nervous_system_plan (status=blocked) and comment_on_nervous_system_plan '
            . 'explaining exactly what capability/tool is missing, so the PM can reassign it to a capable '
            . 'agent or a human. Only mark work done that you ACTUALLY completed with your tools. An honest '
            . "\"I can't do this, here's why\" is correct; a fake \"done\" is a failure.\n\n"
            . 'DO NOT REPEAT YOURSELF. Read "Recent activity on this plan" below — those are notes YOU (or '
            . 'others) already posted. If the plan is already blocked for the same reason and NOTHING has '
            . 'changed since the last note, take NO action: do not re-comment, do not re-set the status, '
            . 'just reply "no change" and stop. Only post a NEW comment when you have genuinely new '
            . "information or progress.\n\n"
            . "Plan: %s\n%s%s",
            $this->plan->getId(),
            $this->plan->status,
            $this->plan->title,
            $this->plan->description !== null && $this->plan->description !== ''
                ? "Details: {$this->plan->description}\n"
                : '',
            $this->recentActivity(),
        );
    }

    private function planChannel(): ?Channel
    {
        if (! $this->channelResolved) {
            /** @var Channel|null $channel */
            $channel = $this->plan->socialChannels()->first();
            $this->channel = $channel;
            $this->channelResolved = true;
        }

        return $this->channel;
    }

    private function lastNoteIsFrom(Users $user): bool
    {
        $last = $this->plan->recentActivityMessages(1)->first();

        return $last !== null && (int) $last->users_id === $user->getId();
    }

    /**
     * The plan's most recent activity notes, so the worker can SEE what it already said and not repeat a
     * block/progress comment when nothing has changed. Empty on a first wake (no notes yet).
     */
    protected function recentActivity(): string
    {
        $notes = $this->plan->recentActivityMessages(self::ACTIVITY_LOOKBACK)
            ->map(fn (Message $m): string => is_array($m->message) ? trim((string) ($m->message['content'] ?? '')) : '')
            ->filter()
            ->reverse()
            ->map(fn (string $c): string => '- ' . Str::limit($c, 300))
            ->implode("\n");

        return $notes !== '' ? "\nRecent activity on this plan (most recent last):\n{$notes}\n" : '';
    }
}
