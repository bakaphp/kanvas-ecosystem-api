<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Plan\Jobs;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;
use Kanvas\Intelligence\Agents\Actions\Chat\AgentChatKernel;
use Kanvas\Intelligence\Sessions\Models\Session;
use Kanvas\NervousSystem\Ledger\Enums\EventStatusEnum;
use Kanvas\NervousSystem\Plan\Actions\PostPlanActivityMessageAction;
use Kanvas\NervousSystem\Plan\Models\Plan;
use Kanvas\Social\Messages\Models\Message;
use Throwable;

/**
 * Single entry point for waking the agent assigned to a Plan. Used by:
 *   - WakeAgentOnPlanChangeListener (plan created / approved)
 *   - ReplyToPlanCommentActivity (human comment on the Activities channel)
 *
 * Both paths land on the same per-plan Session so the agent's LLM context
 * is continuous across all wake-ups for the same plan.
 *
 * Emits two ledger events on success:
 *   plan.agent.invoked      — after the LLM call returns (carries duration_ms)
 *   plan.agent.replied      — after the reply is posted on the channel
 *
 * And one on failure:
 *   plan.agent.invocation_failed — when AgentChatKernel throws
 */
class WakeAgentForPlanJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use KanvasJobsTrait;
    use Queueable;
    use SerializesModels;

    public const string REASON_PLAN_ASSIGNED = 'plan_assigned';
    public const string REASON_COMMENT = 'comment';
    public const string REASON_APPROVED = 'approved';

    /**
     * A delegated task reached a terminal state. The completion rides `$userMessage` rather than
     * being left for the agent to diff out of its context bundle — `ProjectContextService::plans()`
     * is scoped `->open()`, so a plan that rolls up complete drops out entirely and the very fact
     * the agent needs is the one that vanishes.
     */
    public const string REASON_TASK_COMPLETED = 'task_completed';

    public function __construct(
        public readonly Plan $plan,
        public readonly string $reason,
        public readonly ?string $userMessage = null,
    ) {
    }

    public function handle(): void
    {
        // Reset Bouncer scope + app to this plan's app — else the agent/channel Role lookups
        // throw ModelNotFoundException under a leaked worker scope.
        $this->overwriteAppService($this->plan->app);

        $agent = $this->plan->agent;
        $owner = $this->plan->user ?? $agent?->user;

        if ($agent === null || $owner === null) {
            return;
        }

        $session = $this->resolveSession();
        $message = $this->buildMessage();

        $startedAt = microtime(true);

        try {
            $response = new AgentChatKernel(
                agent: $agent,
                session: $session,
                message: $message,
                user: $owner,
            )->execute();
        } catch (Throwable $e) {
            $this->plan->emitLedgerEvent(
                eventType: 'plan.agent.invocation_failed',
                status: EventStatusEnum::ERROR,
                payload: [
                    'agent_id' => $this->plan->agent_id,
                    'session_id' => $session->getId(),
                    'reason' => $this->reason,
                ],
                error: [
                    'message' => $e->getMessage(),
                    'class' => $e::class,
                ],
                durationMs: (int) ((microtime(true) - $startedAt) * 1000),
            );

            throw $e;
        }

        $durationMs = (int) ((microtime(true) - $startedAt) * 1000);

        $this->plan->emitLedgerEvent(
            'plan.agent.invoked',
            payload: [
                'agent_id' => $this->plan->agent_id,
                'session_id' => $session->getId(),
                'reason' => $this->reason,
                'response_length' => strlen($response),
            ],
            durationMs: $durationMs,
        );

        $reply = $this->postReplyOnActivitiesChannel($response);

        if ($reply !== null) {
            $this->plan->emitLedgerEvent(
                'plan.agent.replied',
                payload: [
                    'agent_id' => $this->plan->agent_id,
                    'message_id' => $reply->id,
                    'message_uuid' => $reply->uuid,
                ],
            );
        }
    }

    protected function resolveSession(): Session
    {
        $owner = $this->plan->user ?? $this->plan->agent?->user;

        /** @var Session $session */
        $session = Session::firstOrCreate(
            [
                'apps_id' => $this->plan->apps_id,
                'companies_id' => $this->plan->companies_id,
                'entity_namespace' => Plan::class,
                'entity_id' => $this->plan->id,
            ],
            [
                'uuid' => Str::uuid()->toString(),
                'agents_id' => $this->plan->agent_id,
                'channel_id' => null,
                'content' => '',
                'user' => $owner !== null ? [
                    'id' => $owner->getId(),
                    'name' => trim(($owner->firstname ?? '') . ' ' . ($owner->lastname ?? '')),
                    'email' => $owner->email ?? null,
                ] : [],
            ],
        );

        return $session;
    }

    protected function buildMessage(): string
    {
        if ($this->reason === self::REASON_COMMENT) {
            return sprintf(
                "[NS:plan_comment] plan_id=%d plan_uuid=%s\n\n%s",
                $this->plan->id,
                $this->plan->uuid,
                (string) $this->userMessage,
            );
        }

        if ($this->reason === self::REASON_TASK_COMPLETED) {
            return sprintf(
                "[NS:task_completed] plan_id=%d plan_uuid=%s\n\n%s\n\n"
                . 'Follow up on this: report it to whoever asked, assign any review or next step, '
                . 'and close the plan if the work is finished.',
                $this->plan->id,
                $this->plan->uuid,
                (string) $this->userMessage,
            );
        }

        if ($this->reason === self::REASON_APPROVED) {
            $reviewerNote = $this->plan->review_outcome !== null && $this->plan->review_outcome !== ''
                ? "Reviewer note: {$this->plan->review_outcome}\n"
                : '';

            return sprintf(
                "[NS:plan_approved] plan_id=%d plan_uuid=%s\n\n"
                . 'Your plan has been approved by the human reviewer. '
                . 'Resume execution. Use the nervous-system-working skill '
                . "to refresh plan context if you need to, then continue the work.\n\n"
                . "Title: %s\n%s%s",
                $this->plan->id,
                $this->plan->uuid,
                $this->plan->title,
                $reviewerNote,
                $this->plan->description !== null && $this->plan->description !== ''
                    ? "Description: {$this->plan->description}"
                    : '',
            );
        }

        return sprintf(
            "[NS:plan_assigned] reason=%s plan_id=%d plan_uuid=%s\n\n"
            . 'A plan has been assigned to you. Use the nervous-system-working '
            . 'skill to fetch its full context, plan the work, and execute. '
            . "Report progress on the Activities channel.\n\n"
            . "Title: %s\n%s",
            $this->reason,
            $this->plan->id,
            $this->plan->uuid,
            $this->plan->title,
            $this->plan->description !== null && $this->plan->description !== ''
                ? "Description: {$this->plan->description}"
                : '',
        );
    }

    protected function postReplyOnActivitiesChannel(string $response): ?Message
    {
        return new PostPlanActivityMessageAction(
            $this->plan,
            $response,
            author: $this->plan->agent?->user,
        )->execute();
    }
}
