<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\NervousSystem;

use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\ResolvesPlanForTool;
use Kanvas\NervousSystem\Plan\Actions\PostPlanActivityMessageAction;
use Kanvas\Social\Messages\Models\Message;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use Override;
use Throwable;

/**
 * Lets an agent working a plan leave a progress comment / note on that plan's Activities channel — how
 * a worker reports what it's doing, what it found, or why it's blocked, without changing task state.
 * The PM and humans read these to follow along.
 */
#[AgentTool(name: 'Comment On Plan')]
class CommentOnNervousSystemPlanTool extends Tool
{
    use HasKanvasContext;
    use ResolvesPlanForTool;

    private const int DEDUP_LOOKBACK = 15;

    public function __construct()
    {
        parent::__construct(
            name: 'comment_on_nervous_system_plan',
            description: 'Leave a progress comment or note on a plan you are working. Use this to report '
                . 'what you did, findings, decisions, or blockers — it does not change task status.',
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
                description: 'The plan to comment on (the plan assigned to you).',
                required: true,
            ),
            new ToolProperty(
                name: 'comment',
                type: PropertyType::STRING,
                description: 'The progress note / comment to post.',
                required: true,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(int $plan_id, string $comment): array
    {
        $plan = $this->resolvePlanOrError($plan_id);

        if (is_array($plan)) {
            return $plan;
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
            )->execute();
        } catch (Throwable $e) {
            return ['error' => $e->getMessage()];
        }

        return [
            'plan_id' => $plan->getId(),
            'message_id' => $message?->getId(),
            'posted' => $message !== null,
        ];
    }
}
