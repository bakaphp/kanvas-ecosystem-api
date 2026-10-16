<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\AgentRuntime\Harness\Concerns;

use Kanvas\Intelligence\AgentRuntime\Harness\DataTransferObject\HarnessPermissionRequest;
use Kanvas\Intelligence\AgentRuntime\Harness\DataTransferObject\HarnessQuestion;
use Kanvas\Intelligence\AgentRuntime\Harness\Models\AgentTaskSession;
use Kanvas\NervousSystem\Plan\Actions\PostPlanActivityMessageAction;
use Kanvas\NervousSystem\Plan\Jobs\Traits\AnnouncesPlanOutcome;
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
    use AnnouncesPlanOutcome;

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

    protected function postQuestion(AgentTaskSession $session, HarnessQuestion $question): void
    {
        $this->postBlockingAsk(
            $session,
            "❓ The coding agent needs a decision:\n\n" . $question->describe()
                . $this->howToAnswer($session, 'question', $question->id),
            'coding_question',
            'Coding job waiting on an answer',
        );
    }

    protected function postPermissionRequest(AgentTaskSession $session, HarnessPermissionRequest $permission): void
    {
        $this->postBlockingAsk(
            $session,
            "🔐 The coding agent is asking permission to run:\n\n`" . $permission->describe() . '`'
                . $this->howToAnswer($session, 'permission', $permission->id),
            'coding_permission',
            'Coding job waiting on a permission',
        );
    }

    /**
     * The two ids a person needs to hand this back to the agent.
     *
     * Without them the alert says what is being asked and nothing about which job or which request, so
     * answering means going and looking both up — and the agent, which can clear this itself, is never
     * told to. Named `job` because that is what the agent's tools call it; the column is `task_id`.
     */
    private function howToAnswer(AgentTaskSession $session, string $kind, string $id): string
    {
        return "\n\nJob " . $session->task_id . ' · ' . $kind . ' `' . $id . '`'
            . "\nAsk the agent to answer it — it has the tool and does not need you to click anything.";
    }

    /**
     * Unanswered, the session dies at the timeout and its work is thrown away — so unlike narration
     * this is mentioned and notified, like a finished job. Still `from_ia`, so no agent is woken.
     */
    private function postBlockingAsk(
        AgentTaskSession $session,
        string $body,
        string $verb,
        string $title
    ): void {
        /** @var Plan|null $plan */
        $plan = $session->plan;

        if ($plan === null) {
            return;
        }

        $this->postToPlan($session, ($this->mentionFor($plan) ?? '') . $body, $verb);
        $this->notifyTheAsker($plan, $title, $body);
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
