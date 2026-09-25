<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\AgentRuntime\Harness\Concerns;

use Kanvas\Intelligence\AgentRuntime\Harness\DataTransferObject\HarnessPermissionRequest;
use Kanvas\Intelligence\AgentRuntime\Harness\Models\AgentTaskSession;
use Kanvas\NervousSystem\Plan\Actions\PostPlanActivityMessageAction;
use Kanvas\NervousSystem\Plan\Models\Plan;
use Throwable;

/**
 * Everything a session says to humans goes to the plan's Activities channel — the room where the work
 * already lives, so a supervisor reads one feed rather than a second inbox.
 *
 * Every post is best-effort: a missing channel must never break polling, because the run itself is
 * still valid work whether or not anyone can read about it.
 */
trait PostsSessionActivity
{
    /**
     * @param list<string> $narration
     */
    protected function postNarration(AgentTaskSession $session, array $narration): void
    {
        $text = trim(implode("\n\n", $narration));

        if ($text === '') {
            return;
        }

        $this->postToPlan($session, '🔧 ' . $text, 'coding_progress');
    }

    protected function postQuestion(AgentTaskSession $session, string $question): void
    {
        $this->postToPlan(
            $session,
            "❓ The coding agent needs a decision:\n\n" . $question,
            'coding_question'
        );
    }

    protected function postPermissionRequest(AgentTaskSession $session, HarnessPermissionRequest $permission): void
    {
        $this->postToPlan(
            $session,
            "🔐 The coding agent is asking permission to run:\n\n`" . $permission->describe() . '`',
            'coding_permission'
        );
    }

    protected function postToPlan(AgentTaskSession $session, string $content, string $verb): void
    {
        try {
            /** @var Plan|null $plan */
            $plan = $session->plan;

            if ($plan === null) {
                return;
            }

            new PostPlanActivityMessageAction(
                plan: $plan,
                content: $content,
                verb: $verb,
                // Without this an @mention in the agent's own narration wakes the agent it names, and
                // two agents talk to each other until the budget is gone.
                extraPayload: ['from_ia' => true],
            )->execute();
        } catch (Throwable $e) {
            report($e);
        }
    }
}
