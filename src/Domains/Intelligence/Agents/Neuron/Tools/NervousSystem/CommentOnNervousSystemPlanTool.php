<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\NervousSystem;

use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\NervousSystem\Plan\Actions\PostPlanActivityMessageAction;
use Kanvas\NervousSystem\Plan\Models\Plan;
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
        $plan = Plan::query()
            ->where('id', $plan_id)
            ->fromApp($this->app)
            ->fromCompany($this->company)
            ->notDeleted()
            ->first();

        if ($plan === null) {
            return ['error' => "Plan {$plan_id} was not found."];
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
