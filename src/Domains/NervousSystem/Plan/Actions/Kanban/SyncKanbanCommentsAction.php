<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Plan\Actions\Kanban;

use Kanvas\Intelligence\AgentRuntime\DataTransferObject\KanbanTask;
use Kanvas\Intelligence\AgentRuntime\Enums\KanbanCustomFieldEnum;
use Kanvas\NervousSystem\Plan\Actions\PostPlanActivityMessageAction;
use Kanvas\NervousSystem\Plan\Models\Plan;

/**
 * Mirror the agent's card comments into the Plan's Activities channel (the Hermes → Kanvas half of
 * the comment bridge). All cards belonging to a Plan (root + children) feed the one channel; child
 * comments are prefixed with the card title. Posted as the agent's user so they don't re-trigger a
 * push (the activity loop guard skips the plan's own agent).
 *
 * Loop guards: skip comments authored `kanvas:*` (we wrote them) and a per-plan `LAST_COMMENT_AT`
 * watermark. There is no comment id in the runtime payload, so the watermark keys on `created_at`
 * (epoch, strictly-greater); the only blind spot is two non-kanvas comments in the same second.
 */
final class SyncKanbanCommentsAction
{
    /**
     * @param list<KanbanTask> $cards root + child cards belonging to this plan
     */
    public function __construct(
        private readonly Plan $plan,
        private readonly array $cards,
    ) {
    }

    public function execute(): int
    {
        $watermark = (int) ($this->plan->get(KanbanCustomFieldEnum::LAST_COMMENT_AT->value) ?? 0);
        $rootId = (string) ($this->plan->get(KanbanCustomFieldEnum::TASK_ID->value) ?? '');

        $maxSeen = $watermark;
        $pending = [];

        foreach ($this->cards as $card) {
            foreach ($card->comments as $comment) {
                $createdAt = $comment['createdAt'] ?? 0;

                if ($createdAt > $maxSeen) {
                    $maxSeen = $createdAt;
                }

                if ($createdAt <= $watermark) {
                    continue;
                }

                if (str_starts_with($comment['author'], KanbanCustomFieldEnum::KANVAS_AUTHOR_PREFIX)) {
                    continue;
                }

                $pending[] = [
                    'createdAt' => $createdAt,
                    'text' => $card->id === $rootId
                        ? $comment['body']
                        : '[task: ' . $card->title . '] ' . $comment['body'],
                ];
            }
        }

        usort($pending, static fn (array $a, array $b): int => $a['createdAt'] <=> $b['createdAt']);

        $posted = 0;
        foreach ($pending as $item) {
            if (new PostPlanActivityMessageAction($this->plan, $item['text'])->execute() !== null) {
                $posted++;
            }
        }

        if ($maxSeen > $watermark) {
            $this->plan->set(KanbanCustomFieldEnum::LAST_COMMENT_AT->value, (string) $maxSeen);
        }

        return $posted;
    }
}
