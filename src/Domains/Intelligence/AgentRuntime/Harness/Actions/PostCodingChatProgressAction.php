<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\AgentRuntime\Harness\Actions;

use Baka\Support\Str;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Kanvas\Intelligence\AgentRuntime\Harness\DataTransferObject\HarnessTick;
use Kanvas\Intelligence\AgentRuntime\Harness\Models\AgentTaskSession;

/**
 * A turn can run for many minutes (a build, a test suite, an app launch) with nothing reaching the chat
 * until it ends, and a person cannot tell a working agent from a stuck one. While a person's chat turn
 * runs, each poll says it is still going, for how long, and what the agent is doing. A workflow task run
 * stays quiet: an agent is waiting on it, not a person.
 */
class PostCodingChatProgressAction
{
    /** The turn's own "On it" covers its first minute. */
    private const int QUIET_START_SECONDS = 45;

    /** Two pollers on one session must not double the updates. */
    private const int MIN_GAP_SECONDS = 50;

    public function __construct(
        private readonly AgentTaskSession $session,
        private readonly HarnessTick $tick,
    ) {
    }

    public function execute(): ?string
    {
        if (! $this->session->isChat() || $this->session->isTaskRun()) {
            return null;
        }

        $startedAt = $this->tick->turnStartedAt;
        $elapsed = $startedAt === null ? null : Carbon::now()->getTimestamp() - $startedAt;

        if ($elapsed !== null && $elapsed < self::QUIET_START_SECONDS) {
            return null;
        }

        if (! Cache::add('coding-chat-progress:' . $this->session->getId(), true, self::MIN_GAP_SECONDS)) {
            return null;
        }

        $update = '⏳ Still working'
            . ($elapsed !== null ? ' · ' . max(1, intdiv($elapsed, 60)) . ' min' : '')
            . ($this->tick->activity !== null ? ' · ' . $this->tick->activity : '');

        $note = trim((string) Arr::last($this->tick->narration));

        if ($note !== '') {
            $update .= "\n\n> " . Str::limit(str_replace("\n", ' ', $note), 300);
        }

        new PostCodingChatMessageAction($this->session, $update)->execute();

        return $update;
    }
}
