<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\AgentRuntime\Harness\Actions;

use Kanvas\Intelligence\AgentRuntime\Harness\DataTransferObject\HarnessTick;
use Kanvas\Intelligence\AgentRuntime\Harness\Enums\HarnessStatusEnum;
use Kanvas\Intelligence\AgentRuntime\Harness\Jobs\WatchCodingChatJob;
use Kanvas\Intelligence\AgentRuntime\Harness\Models\AgentTaskSession;

/**
 * A finished turn of a coding chat: what the agent said goes to the conversation, and the session parks
 * as IDLE waiting for the next message instead of finalizing. `turn_offset` moves past this turn's
 * `idle`, or the next turn would be judged finished — and failed — by this one's outcome.
 *
 * A failed turn parks too: a provider error or an interrupt is something to say, not a reason to close a
 * conversation the person may want to retry in.
 */
class DeliverCodingChatTurnAction
{
    public function __construct(
        private readonly AgentTaskSession $session,
        private readonly HarnessTick $tick,
    ) {
    }

    public function execute(): void
    {
        if ($this->session->isTaskRun()) {
            $this->closeTheRun();

            return;
        }

        $this->session->turn_offset = $this->tick->closedTurns;
        $this->session->status = HarnessStatusEnum::IDLE->value;
        $this->session->touchHeartbeat();
        $this->session->saveOrFail();

        new PostCodingChatMessageAction($this->session, $this->content())->execute();

        // Unique per session, so this only starts a watch that is not already running.
        WatchCodingChatJob::dispatch($this->session->app, $this->session->getId());
    }

    /**
     * Closing records the run's task as done or blocked, and that is what resumes the task that started
     * it. The checkout is released for the next run: nobody will send this one a second message.
     */
    private function closeTheRun(): void
    {
        $failed = $this->tick->status === HarnessStatusEnum::FAILED;

        new CloseCodingChatAction(
            session: $this->session,
            reason: $failed ? 'The factory run failed: ' . (string) $this->tick->error : null,
            status: $failed ? HarnessStatusEnum::FAILED : HarnessStatusEnum::COMPLETED,
            notify: false,
            interrupt: false,
            reply: $this->tick->turnReply ?? 'Done, with nothing to report.',
        )->execute();
    }

    private function content(): string
    {
        $reply = $this->tick->turnReply;

        if ($this->tick->status !== HarnessStatusEnum::FAILED) {
            return $reply ?? '✅ Done — nothing to report this turn.';
        }

        $said = $reply !== null ? "\n\n" . $reply : '';

        if (str_contains((string) $this->tick->error, '"interrupted"')) {
            return '⏹ Turn stopped.' . $said;
        }

        return '⚠️ This turn failed: ' . (string) $this->tick->error . $said;
    }
}
