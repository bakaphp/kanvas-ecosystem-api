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

    /**
     * Each minute is said once: two pollers on one session must not double it, and a tick that lands a few
     * seconds after the previous one (the poller re-aligning to the turn's minutes) must not swallow it.
     * The marker only has to outlive its own minute, with room for a late poll.
     */
    private const int MINUTE_TTL_SECONDS = 120;

    /** How far past the turn's minute the next poll lands, so its message names that minute. */
    private const int MINUTE_MARGIN_SECONDS = 5;

    public function __construct(
        private readonly AgentTaskSession $session,
        private readonly HarnessTick $tick,
    ) {
    }

    public function execute(): ?string
    {
        $elapsed = self::elapsedInPersonsTurn($this->session, $this->tick);

        if ($elapsed === null || $elapsed < self::QUIET_START_SECONDS) {
            return null;
        }

        $minute = max(1, intdiv($elapsed, 60));
        $said = 'coding-chat-progress:' . $this->session->getId() . ':' . $this->tick->turnStartedAt . ':' . $minute;

        if (! Cache::add($said, true, self::MINUTE_TTL_SECONDS)) {
            return null;
        }

        $update = '⏳ Still working · ' . $minute . ' min'
            . ($this->tick->activity !== null ? ' · ' . $this->tick->activity : '');

        $note = trim((string) Arr::last($this->tick->narration));

        if ($note !== '') {
            $update .= "\n\n> " . Str::limit(str_replace("\n", ' ', $note), 300);
        }

        new PostCodingChatMessageAction($this->session, $update)->execute();

        return $update;
    }

    /**
     * When the poller should look again for this message to name the next minute: a flat 60s plus the
     * poll's own run time drifts a few seconds a tick and skips a minute every ten or so. Null when no
     * person is waiting on a turn.
     */
    public static function secondsToNextMinute(AgentTaskSession $session, HarnessTick $tick): ?int
    {
        $elapsed = self::elapsedInPersonsTurn($session, $tick);

        return $elapsed === null ? null : 60 - ($elapsed % 60) + self::MINUTE_MARGIN_SECONDS;
    }

    /**
     * No start time means the agent has not written anything in this turn yet: it has only just begun.
     */
    private static function elapsedInPersonsTurn(AgentTaskSession $session, HarnessTick $tick): ?int
    {
        if (! $session->isChat() || $session->isTaskRun() || $tick->turnStartedAt === null) {
            return null;
        }

        return max(0, Carbon::now()->getTimestamp() - $tick->turnStartedAt);
    }
}
