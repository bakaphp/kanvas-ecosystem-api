<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Plan\Listeners;

use Kanvas\Intelligence\Agents\Enums\AgentProviderEnum;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentDeployment;
use Kanvas\Intelligence\Agents\Neuron\Contracts\BehavesAsKanvasAgent;
use Kanvas\Intelligence\Agents\Services\AgentConversationBudget;
use Kanvas\NervousSystem\Plan\Jobs\PushCommentToKanbanJob;
use Kanvas\NervousSystem\Plan\Jobs\WakeAgentForPlanJob;
use Kanvas\NervousSystem\Plan\Models\Plan;
use Kanvas\NervousSystem\Plan\Support\MentionHandle;
use Kanvas\NervousSystem\Project\Jobs\WakeWorkerForPlanJob;
use Kanvas\Social\Channels\Events\ChannelMessageAttachedEvent;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Messages\Models\Message;

/**
 * A comment on a plan's board reaches the agent working it — natively, with nothing to configure.
 *
 * `ReplyToPlanCommentActivity` can do the same thing, but only where an admin wired a workflow rule
 * for it. Someone writing on a plan and getting silence is not a feature switched off, it is the board
 * not working — so this belongs in the domain, not in per-company configuration. The activity is for
 * wiring OTHER channels into a plan; this covers the plan's own.
 */
class WakePlanAgentOnChannelCommentListener
{
    public function handle(ChannelMessageAttachedEvent $event): void
    {
        $plan = $this->planOn($event->channel, $event->message);

        if ($plan === null) {
            return;
        }

        $agent = $plan->agent;

        if ($agent === null || ! $agent->is_active) {
            return;
        }

        // The plan's own agent talking on its own board. Anyone else — a human, or another agent
        // collaborating on the plan — is someone it should hear from.
        if ((int) $event->message->users_id === (int) ($agent->user?->getId() ?? 0)) {
            return;
        }

        $comment = trim($event->message->contentText());

        if ($comment === '' || $this->isChatTurn($event->message)) {
            return;
        }

        $namesTheAssignee = MentionHandle::isNamedIn($comment, $agent->user, $event->message->app);

        // An agent's comment is a NOTE on the record; it becomes a question only by naming who it
        // wants an answer from. A person is never held to that — they should not have to know
        // handles to reach the agent working their plan.
        if ($this->postedByAgent($event->message) && ! $namesTheAssignee) {
            return;
        }

        // The mention path answers a Neuron agent and threads the reply under the question, charging
        // its own hop there. It drops anything else without a trace, so those are woken here.
        if ($namesTheAssignee && $this->mentionPathHandles($agent)) {
            return;
        }

        // An exchange between two agents has no natural end, so each agent-authored wake is charged —
        // including one that opens a NEW thread, which is how the pair on plan 20355 bought themselves
        // a second budget after spending the first. A person speaking resets it: that is the signal
        // the conversation is wanted.
        if (! $this->postedByAgent($event->message)) {
            AgentConversationBudget::reset($event->channel);
        } elseif (! AgentConversationBudget::spend($event->channel)) {
            return;
        }

        $plan->emitLedgerEvent(
            'plan.agent.wake_dispatched',
            payload: [
                'agent_id' => $agent->getId(),
                'reason' => WakeAgentForPlanJob::REASON_COMMENT,
                'source' => 'listener',
                'message_id' => $event->message->getId(),
            ],
        );

        // Kanban-driven runtime agents (Hermes) are steered by comments on the card, not chat turns —
        // they replay the thread on their next spawn, so a chat wake would run the work twice.
        $deployment = $agent->activeDeployment;

        if ($deployment instanceof AgentDeployment
            && $deployment->isRunning()
            && AgentProviderEnum::forDeployment($deployment)->isHermes()
        ) {
            PushCommentToKanbanJob::dispatch($plan, $comment, (int) $event->message->users_id);

            return;
        }

        // A plan under a project is delegated work, and its worker needs the board tools
        // WakeWorkerForPlanJob injects for the run — WakeAgentForPlanJob wakes the agent with only
        // its own toolset, so it cannot move a task or answer on the board and blocks itself saying
        // exactly that. The comment reaches it either way: the worker prompt carries recent activity.
        if ($plan->project_id !== null) {
            WakeWorkerForPlanJob::dispatch($plan);

            return;
        }

        WakeAgentForPlanJob::dispatch($plan, WakeAgentForPlanJob::REASON_COMMENT, $comment);
    }

    /**
     * Whether `RespondToMentionJob` will actually answer for this agent.
     *
     * It only runs a Neuron-shaped handler, so a hosted or container agent named in a comment would
     * otherwise be left to a path that returns without a trace.
     */
    private function mentionPathHandles(Agent $agent): bool
    {
        $handler = $agent->type?->handler;

        return is_string($handler)
            && class_exists($handler)
            && is_a($handler, BehavesAsKanvasAgent::class, true);
    }

    /**
     * Whether a machine wrote this, read off the message rather than inferred from its author.
     *
     * Author identity cannot answer it: agents share users with real people (11 agents sit on one
     * real person's user in production), so `Agent::fromUser()` calls a human's comment agent-authored and
     * would ration the one participant who is supposed to reset the budget.
     */
    private function postedByAgent(Message $message): bool
    {
        $payload = $message->message;

        return is_array($payload)
            && (($payload['from_agent'] ?? false) === true || ($payload['from_ia'] ?? false) === true);
    }

    /**
     * A wake's own prompt, echoed back onto this channel.
     *
     * `PersistChatTurnToSocialAction` writes both halves of every agent turn here, and the incoming
     * half is authored by the plan's OWNER — so it passes the author guard, reads as a fresh comment,
     * and wakes the agent again. Chat turns carry `session_id`; a board comment does not.
     */
    private function isChatTurn(Message $message): bool
    {
        return is_array($message->message) && isset($message->message['session_id']);
    }

    /**
     * The channel pivot is the usual path; the message's own polymorphic entity covers a message
     * written straight against a plan.
     */
    private function planOn(Channel $channel, Message $message): ?Plan
    {
        $planId = match (true) {
            $channel->entity_namespace === Plan::class => (int) $channel->entity_id,
            $message->entity_namespace === Plan::class && $message->entity_id !== null => (int) $message->entity_id,
            default => null,
        };

        if ($planId === null) {
            return null;
        }

        return Plan::query()
            ->where('id', $planId)
            ->where('is_deleted', 0)
            ->first();
    }
}
