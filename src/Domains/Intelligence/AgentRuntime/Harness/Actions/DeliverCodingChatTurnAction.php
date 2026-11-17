<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\AgentRuntime\Harness\Actions;

use Baka\Support\Str;
use Kanvas\Intelligence\AgentRuntime\Harness\Contracts\HarnessTransport;
use Kanvas\Intelligence\AgentRuntime\Harness\DataTransferObject\HarnessTick;
use Kanvas\Intelligence\AgentRuntime\Harness\Enums\HarnessStatusEnum;
use Kanvas\Intelligence\AgentRuntime\Harness\HarnessFactory;
use Kanvas\Intelligence\AgentRuntime\Harness\Jobs\PollHarnessSessionJob;
use Kanvas\Intelligence\AgentRuntime\Harness\Jobs\WatchCodingChatJob;
use Kanvas\Intelligence\AgentRuntime\Harness\Models\AgentTaskSession;
use Throwable;

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
    /**
     * A task run that keeps stopping is given this many turns in all before it is closed as failed: the
     * first, plus the nudges to carry on.
     */
    public const int MAX_TASK_RUN_TURNS = 4;

    public function __construct(
        private readonly AgentTaskSession $session,
        private readonly HarnessTick $tick,
        private readonly ?HarnessTransport $transport = null,
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
     *
     * A turn also ends when the model simply stops talking, mid-work, and closing then recorded a ticket
     * as done with nothing pushed (its last words were "Now restart both apps"). So a run is done only
     * when it says it is, with the marker its brief asks for, or says BLOCKED; otherwise it is told to
     * carry on, and after `MAX_TASK_RUN_TURNS` it closes as failed, never as done.
     */
    private function closeTheRun(): void
    {
        $failed = $this->tick->status === HarnessStatusEnum::FAILED;
        $reply = Str::trimToNull($this->tick->turnReply);

        if (! $failed && ! $this->saysItIsFinished($reply)) {
            if ($this->tick->closedTurns < self::MAX_TASK_RUN_TURNS && $this->carryOn()) {
                return;
            }

            $this->close(
                HarnessStatusEnum::FAILED,
                'The factory run stopped before it finished, ' . $this->tick->closedTurns . ' times. Its work so '
                    . 'far is saved on its branch; send the step back to continue from there.',
                $reply,
            );

            return;
        }

        $this->close(
            $failed ? HarnessStatusEnum::FAILED : HarnessStatusEnum::COMPLETED,
            $failed ? 'The factory run failed: ' . (string) $this->tick->error : null,
            $reply !== null ? Str::trimToNull(str_replace(AgentTaskSession::RUN_FINISHED_MARKER, '', $reply)) : null,
        );
    }

    private function close(HarnessStatusEnum $status, ?string $reason, ?string $reply): void
    {
        new CloseCodingChatAction(
            session: $this->session,
            reason: $reason,
            status: $status,
            notify: false,
            interrupt: false,
            reply: $reply ?? 'Done, with nothing to report.',
        )->execute();
    }

    private function saysItIsFinished(?string $reply): bool
    {
        if ($reply === null) {
            return false;
        }

        $opening = ltrim($reply, " \t\n\r\0\x0B#*>_`-");

        return str_contains($reply, AgentTaskSession::RUN_FINISHED_MARKER)
            || stripos($opening, 'BLOCKED') === 0;
    }

    /**
     * The next turn of the same session, so the agent keeps its context and its checkout.
     */
    private function carryOn(): bool
    {
        try {
            $this->session->turn_offset = $this->tick->closedTurns;
            $this->session->status = HarnessStatusEnum::RUNNING->value;
            $this->session->touchHeartbeat();
            $this->session->saveOrFail();

            HarnessFactory::forSession($this->session, $this->transport)->ask(
                $this->session,
                'You stopped before the task was finished: your last reply did not end with the line '
                    . AgentTaskSession::RUN_FINISHED_MARKER . '. Carry on from exactly where you are and do every '
                    . 'step the task still needs. When all of it is done, reply with the summary the task asks '
                    . 'for and end it with ' . AgentTaskSession::RUN_FINISHED_MARKER . '. If something stops '
                    . 'you, start your reply with BLOCKED and say why.'
            );
        } catch (Throwable $e) {
            report($e);

            return false;
        }

        PollHarnessSessionJob::dispatch($this->session->app, $this->session->getId());

        return true;
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
