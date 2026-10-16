<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\AgentRuntime\Harness\Actions;

use Kanvas\Intelligence\AgentRuntime\Harness\Concerns\PostsSessionActivity;
use Kanvas\Intelligence\AgentRuntime\Harness\DataTransferObject\HarnessTick;
use Kanvas\Intelligence\AgentRuntime\Harness\Models\AgentTaskSession;
use Kanvas\Intelligence\AgentRuntime\Harness\Services\SessionCostService;

/**
 * Writes one tick onto the session and says anything new out loud.
 *
 * Shared by the queued poller and the foreground smoke-test command: the moment those two keep their
 * own copies, one of them starts drifting — the first version of this cost nothing to the command and
 * every session it ran reported $0.
 */
class AbsorbHarnessTickAction
{
    use PostsSessionActivity;

    public function __construct(
        private readonly AgentTaskSession $session,
        private readonly HarnessTick $tick,
        private readonly bool $announce = true,
    ) {
    }

    public function execute(): AgentTaskSession
    {
        $usage = $this->tick->usage;
        $wasWaiting = $this->session->harnessStatus()->isWaitingOnAHuman();

        $this->session->input_tokens = $usage->inputTokens;
        $this->session->output_tokens = $usage->outputTokens;
        $this->session->cache_read_tokens = $usage->cacheReadTokens;
        $this->session->cache_write_tokens = $usage->cacheWriteTokens;
        $this->session->estimated_cost = (string) new SessionCostService()->costFor($this->session, $usage);
        $this->session->status = $this->tick->status->value;
        $this->session->last_cursor = $this->tick->cursor ?? $this->session->last_cursor;
        $this->session->last_message_at = $this->tick->lastMessageAt ?? $this->session->last_message_at;

        if ($this->tick->error !== null) {
            $this->session->error_message = $this->tick->error;
        }

        $this->session->touchHeartbeat();
        $this->session->saveOrFail();

        if ($this->announce) {
            $this->say($wasWaiting);
        }

        return $this->session;
    }

    /**
     * Every poll re-reports a pending permission, so announcing per tick buries the feed in identical
     * messages. Announced on the way IN to the waiting state; parking again after a resume says so again.
     */
    private function say(bool $wasWaiting): void
    {
        if ($this->tick->hasNarration()) {
            $this->postNarration($this->session, $this->tick->narration);
        }

        if ($wasWaiting) {
            return;
        }

        foreach ($this->tick->questions as $question) {
            $this->postQuestion($this->session, $question);
        }

        foreach ($this->tick->permissions as $permission) {
            $this->postPermissionRequest($this->session, $permission);
        }
    }
}
