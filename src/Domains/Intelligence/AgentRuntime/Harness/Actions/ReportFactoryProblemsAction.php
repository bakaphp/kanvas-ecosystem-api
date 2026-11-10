<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\AgentRuntime\Harness\Actions;

use Illuminate\Support\Facades\Cache;
use Kanvas\Intelligence\AgentRuntime\Harness\Contracts\HarnessTransport;
use Kanvas\Intelligence\AgentRuntime\Harness\DataTransferObject\FactoryHealth;
use Kanvas\Intelligence\AgentRuntime\Harness\Enums\HarnessStatusEnum;
use Kanvas\Intelligence\AgentRuntime\Harness\Exceptions\HarnessTransportException;
use Kanvas\Intelligence\AgentRuntime\Harness\HarnessFactory;
use Kanvas\Intelligence\AgentRuntime\Harness\Models\AgentTaskSession;

/**
 * Posts what broke on the factory Mac since the last snapshot. The agent is told too when it is mid-turn,
 * or it reports "it's running" over an emulator that has been crash-looping for minutes. Between turns
 * only the person is told: a prompt would start a paid turn nobody asked for.
 *
 * The previous snapshot lives in the cache, not the row: losing it costs one comparison, after which
 * the next snapshot is the baseline again.
 */
class ReportFactoryProblemsAction
{
    private const int SNAPSHOT_TTL_SECONDS = 86400;

    public function __construct(
        private readonly AgentTaskSession $session,
        private readonly FactoryHealth $health,
        private readonly ?HarnessTransport $transport = null,
    ) {
    }

    /**
     * @return list<string>
     */
    public function execute(): array
    {
        $problems = $this->health->problemsSince(self::lastSnapshotOf($this->session));
        Cache::put(self::cacheKey($this->session), $this->health->toArray(), self::SNAPSHOT_TTL_SECONDS);

        if ($problems === []) {
            return [];
        }

        $list = '- ' . implode("\n- ", $problems);

        new PostCodingChatMessageAction($this->session, "🖥 On the factory Mac:\n" . $list)->execute();

        if ($this->session->harnessStatus() === HarnessStatusEnum::RUNNING) {
            $this->tellTheAgent($list);
        }

        return $problems;
    }

    /**
     * Crashes are asked for from the last snapshot on, on the Mac's own clock; the first look covers
     * the whole chat.
     */
    public static function since(AgentTaskSession $session): int
    {
        return self::lastSnapshotOf($session)?->at
            ?? ($session->started_at ?? $session->created_at)?->getTimestamp()
            ?? time();
    }

    private static function lastSnapshotOf(AgentTaskSession $session): ?FactoryHealth
    {
        $snapshot = Cache::get(self::cacheKey($session));

        return is_array($snapshot) ? FactoryHealth::from($snapshot) : null;
    }

    private static function cacheKey(AgentTaskSession $session): string
    {
        return 'coding-chat-factory-health:' . $session->getId();
    }

    private function tellTheAgent(string $list): void
    {
        try {
            HarnessFactory::forSession($this->session, $this->transport)->steer(
                $this->session,
                "[Factory monitor: automatic, not from the person]\n" . $list
                . "\nTake this into account, and check it before you report anything as running."
            );
        } catch (HarnessTransportException) {
            // The person has already been told; a turn that just closed is the poller's to report.
        }
    }
}
