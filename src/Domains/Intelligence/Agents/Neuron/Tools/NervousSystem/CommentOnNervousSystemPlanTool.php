<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\NervousSystem;

use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\ResolvesPlanForTool;
use Kanvas\NervousSystem\Plan\Actions\PostPlanActivityMessageAction;
use Kanvas\NervousSystem\Plan\Models\Plan as PlanModel;
use Kanvas\Social\Messages\Models\Message;
use NeuronAI\Tools\HasRunKey;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\TrackByInputs;
use Override;
use Throwable;

/**
 * Lets an agent working a plan leave a progress comment / note on that plan's Activities channel — how
 * a worker reports what it's doing, what it found, or why it's blocked, without changing task state.
 * The PM and humans read these to follow along.
 */
#[AgentTool(name: 'Comment On Plan', category: 'nervous_system')]
class CommentOnNervousSystemPlanTool extends Tool implements HasRunKey
{
    use HasKanvasContext;
    use TrackByInputs;
    use ResolvesPlanForTool;

    private const int DEDUP_LOOKBACK = 15;

    /** How far back a reply target may live — a thread older than this is a new conversation. */
    private const int REPLY_LOOKBACK = 40;

    public function __construct()
    {
        parent::__construct(
            name: 'comment_on_nervous_system_plan',
            description: 'Leave a progress comment or note on a plan: what you did, what you found, a '
                . 'decision, a blocker, or a status update someone asked for. It does not change task '
                . 'status. This writes to the PLAN\'s own Activities channel, which is where the record '
                . 'belongs — anyone who opens the plan sees it, and it stays with the work instead of '
                . 'being buried in one conversation. A comment is a NOTE and wakes nobody — to get an '
                . 'answer, @mention the agent or person you want it from inside the comment text. When '
                . 'you are CONTINUING an exchange — answering someone, or following up on something '
                . 'you already asked — pass reply_to_message_id so it lands in that thread instead of '
                . 'starting a new one.',
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
                name: 'plan_id',
                type: PropertyType::INTEGER,
                description: 'The plan to comment on.',
                required: true,
            ),
            new ToolProperty(
                name: 'comment',
                type: PropertyType::STRING,
                description: 'The progress note / comment to post.',
                required: true,
            ),
            new ToolProperty(
                name: 'reply_to_message_id',
                type: PropertyType::INTEGER,
                description: 'The message you are answering, from read_plan_activity. Pass it whenever '
                    . 'you are continuing an exchange so your comment lands IN that thread — omitting it '
                    . 'starts a new thread, which is how one conversation ends up as several unconnected '
                    . 'ones. Omit only when you are raising something new.',
                required: false,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(int $plan_id, string $comment, ?int $reply_to_message_id = null): array
    {
        $plan = $this->resolvePlanOrError($plan_id);

        if (is_array($plan)) {
            return $plan;
        }

        $replyTo = $this->resolveReplyTarget($plan, $reply_to_message_id);

        if (is_array($replyTo)) {
            return $replyTo;
        }

        // Deterministic anti-spam backstop: skip if this exact note is already among the plan's recent
        // notes (scan several, not just the last — another message may have landed in between).
        $needle = trim($comment);

        $alreadyPosted = $needle !== '' && $plan->recentActivityMessages(self::DEDUP_LOOKBACK)
            ->contains(fn (Message $msg): bool => is_array($msg->message)
                && trim((string) ($msg->message['content'] ?? '')) === $needle);

        if ($alreadyPosted) {
            return [
                'plan_id' => $plan->getId(),
                'posted' => false,
                'skipped' => 'duplicate',
                'message' => 'This exact note is already on the plan — not re-posting. Do NOT call '
                    . 'comment_on_nervous_system_plan again with the same text unless something changed.',
            ];
        }

        try {
            $message = new PostPlanActivityMessageAction(
                $plan,
                $comment,
                author: $this->user,
                replyTo: $replyTo,
            )->execute();
        } catch (Throwable $e) {
            return ['error' => $e->getMessage()];
        }

        return [
            'plan_id' => $plan->getId(),
            'message_id' => $message?->getId(),
            'posted' => $message !== null,
            'in_reply_to' => $replyTo?->getId(),
        ];
    }

    /**
     * The message this comment answers, or a structured error for an id that is not on this plan.
     *
     * Scoped to the plan's own board: an LLM-supplied id could otherwise thread this comment onto an
     * unrelated conversation, and the id is exactly the kind of value a model invents.
     *
     * @return Message|array<string, mixed>|null
     */
    private function resolveReplyTarget(PlanModel $plan, ?int $messageId): Message|array|null
    {
        if ($messageId === null) {
            return null;
        }

        $message = $plan->recentActivityMessages(self::REPLY_LOOKBACK)
            ->first(fn (Message $candidate): bool => $candidate->getId() === $messageId);

        if ($message === null) {
            return [
                'error' => sprintf(
                    'Message %d is not among the recent activity on plan %d, so this comment cannot be '
                    . 'threaded under it. Post without reply_to_message_id, or read the plan first to '
                    . 'get a real message id.',
                    $messageId,
                    $plan->getId(),
                ),
            ];
        }

        return $message;
    }
}
