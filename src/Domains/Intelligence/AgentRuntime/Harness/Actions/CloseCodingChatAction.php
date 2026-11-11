<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\AgentRuntime\Harness\Actions;

use Illuminate\Support\Carbon;
use Kanvas\Intelligence\AgentRuntime\Harness\DataTransferObject\CodingOutcome;
use Kanvas\Intelligence\AgentRuntime\Harness\DataTransferObject\HarnessDiff;
use Kanvas\Intelligence\AgentRuntime\Harness\Enums\HarnessStatusEnum;
use Kanvas\Intelligence\AgentRuntime\Harness\Exceptions\HarnessTransportException;
use Kanvas\Intelligence\AgentRuntime\Harness\HarnessFactory;
use Kanvas\Intelligence\AgentRuntime\Harness\Models\AgentTaskSession;
use Throwable;

/**
 * Ends a coding chat: stops a turn still running, hands the checkout back to the factory (which removes
 * its session files and the lock, leaving the branch and any uncommitted work where `akira` would), and
 * records the outcome on the task. The one exit every path takes — `/end`, a failed launch, a limit, the
 * sweeper — so the lock can never be left held by a chat that is gone.
 */
class CloseCodingChatAction
{
    public function __construct(
        private readonly AgentTaskSession $session,
        private readonly ?string $reason = null,
        private readonly HarnessStatusEnum $status = HarnessStatusEnum::COMPLETED,
        private readonly bool $notify = true,
        /** False when the runtime is already unreachable, where the call could only fail and report. */
        private readonly bool $interrupt = true,
        /** The agent's last answer: on a task run it is the result the task that started it receives. */
        private readonly ?string $reply = null,
    ) {
    }

    public function execute(): string
    {
        if ($this->interrupt && $this->session->external_session_id !== null && $this->session->isLive()) {
            HarnessFactory::interrupt($this->session);
        }

        $released = $this->release();

        $this->session->status = $this->status->value;
        $this->session->completed_at = Carbon::now();
        $this->session->launch_stage = null;

        if ($this->reason !== null) {
            $this->session->error_message = $this->reason;
        }

        $this->session->saveOrFail();

        try {
            new RecordCodingOutcomeAction($this->session, $this->outcome())->execute();
        } catch (Throwable $e) {
            report($e);
        }

        $summary = $this->summary($released);

        if ($this->notify) {
            new PostCodingChatMessageAction($this->session, $summary)->execute();
        }

        return $summary;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function release(): ?array
    {
        $factory = HarnessFactory::codingFactoryFor($this->session);

        if ($factory === null || $this->session->repo_slug === null) {
            return null;
        }

        // Always attempted, even for a launch that failed: `open` takes the lock before anything else can
        // fail, and only this releases it. A refusal (the lock belongs to someone else) is expected.
        try {
            return $factory->release($this->session->repo_slug, $this->session->uuid);
        } catch (HarnessTransportException) {
            return null;
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }

    private function outcome(): CodingOutcome
    {
        return match ($this->status) {
            HarnessStatusEnum::FAILED => CodingOutcome::failed(null, (string) $this->reason),
            HarnessStatusEnum::CANCELLED => CodingOutcome::cancelled(null, (string) $this->reason),
            default => CodingOutcome::ran(new HarnessDiff(), $this->reply),
        };
    }

    /**
     * @param array<string, mixed>|null $released
     */
    private function summary(?array $released): string
    {
        $repo = (string) $this->session->repo_slug;
        $where = $this->session->branch !== null
            ? 'The work stays in **' . $repo . '** on branch `' . $this->session->branch . '`'
            : 'Nothing was started in **' . $repo . '**';
        $changes = (int) ($released['changes'] ?? 0);
        $tail = $changes > 0 ? ' with ' . $changes . ' uncommitted change(s).' : '.';
        $freed = $released === null && $this->session->session_data_path !== null
            ? "\n\nI could not reach the factory to free the checkout; it may still be locked."
            : '';

        if ($this->status === HarnessStatusEnum::COMPLETED) {
            return '✅ Chat closed. ' . $where . $tail . $freed;
        }

        return '⚠️ ' . trim((string) $this->reason) . "\n\n" . $where . $tail
            . ' Start again with `/repo <App> <what to do>`.' . $freed;
    }
}
