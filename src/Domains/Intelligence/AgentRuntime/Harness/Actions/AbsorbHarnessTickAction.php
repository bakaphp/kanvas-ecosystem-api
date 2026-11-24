<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\AgentRuntime\Harness\Actions;

use Illuminate\Support\Facades\Cache;
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
            $this->say();
        }

        return $this->session;
    }

    /**
     * Every poll re-reports a pending ask, so each one is announced once, by its id: an agent can ask
     * again before a poll sees it running, and the session never leaves the waiting state in between.
     */
    private function say(): void
    {
        if ($this->tick->hasNarration()) {
            $this->postNarration($this->session, $this->tick->narration);
        }

        foreach ($this->tick->questions as $question) {
            if ($this->firstTimeAsked($question->id)) {
                $this->postQuestion($this->session, $question);
            }
        }

        foreach ($this->tick->permissions as $permission) {
            if ($this->firstTimeAsked($permission->id)) {
                $this->postPermissionRequest($this->session, $permission);
            }
        }
    }

    private function firstTimeAsked(string $askId): bool
    {
        return Cache::add('harness:asked:' . $this->session->getId() . ':' . $askId, true, now()->addDays(2));
    }
}
