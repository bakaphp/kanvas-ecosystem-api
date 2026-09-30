<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\AgentRuntime\Harness\Services;

use Baka\Support\Str;
use Illuminate\Support\Carbon;
use Kanvas\Intelligence\AgentRuntime\Harness\DataTransferObject\HarnessTick;
use Kanvas\Intelligence\AgentRuntime\Harness\HarnessFactory;
use Kanvas\Intelligence\AgentRuntime\Harness\Models\AgentTaskSession;

/**
 * What a person needs to decide whether a run that hit its limit deserves more: was it working or
 * stuck, what has it produced, and what did it last say. Without it a wedged run and a nearly-finished
 * one look identical.
 */
class SessionPostMortemService
{
    /** Longer than any single tool call we have seen, short of a whole limit block. */
    private const int WEDGED_AFTER_MINUTES = 15;
    private const int MAX_FILES_LISTED = 10;
    private const int MAX_LAST_SAID_CHARS = 600;

    /**
     * @return array{text: string, files: list<string>, silent_minutes: int|null}
     */
    public function describe(AgentTaskSession $session, ?HarnessTick $tick, string $limit): array
    {
        $files = HarnessFactory::diffOrEmpty($session)->paths();
        $silentMinutes = $this->minutesSinceLastMessage($session);

        $lines = [
            'Coding job ' . $session->task_id . ' hit its ' . $limit . '.',
            '',
            'Model: ' . ($session->model ?? 'unknown') . ' · running ' . intdiv($session->elapsedSeconds(), 60) . ' min'
                . ' · ~$' . number_format((float) $session->estimated_cost, 2)
                . ' · ' . number_format($session->input_tokens) . ' in / ' . number_format($session->output_tokens) . ' out',
            'Last agent message: ' . $this->silenceLine($silentMinutes),
            'Files changed: ' . $this->filesLine($files),
        ];

        if ($session->branch !== null) {
            $lines[] = 'Branch: ' . $session->branch;
        }

        if ($tick?->lastSaid !== null) {
            $lines[] = '';
            $lines[] = 'Last thing it said:';
            $lines[] = '> ' . str_replace("\n", "\n> ", Str::limit($tick->lastSaid, self::MAX_LAST_SAID_CHARS));
        }

        return [
            'text' => implode("\n", $lines),
            'files' => $files,
            'silent_minutes' => $silentMinutes,
        ];
    }

    /**
     * @param list<string> $files
     */
    private function filesLine(array $files): string
    {
        if ($files === []) {
            return 'none';
        }

        $listed = implode(', ', array_slice($files, 0, self::MAX_FILES_LISTED));

        return count($files) . ' — ' . $listed . (count($files) > self::MAX_FILES_LISTED ? ', …' : '');
    }

    private function silenceLine(?int $minutes): string
    {
        if ($minutes === null) {
            return 'never — it produced no output at all';
        }

        return $minutes . ' min before the stop'
            . ($minutes >= self::WEDGED_AFTER_MINUTES ? ' ← likely wedged, not slow' : ' (it was still working)');
    }

    /** `last_message_at` is the runtime's own epoch-milliseconds stamp, not a datetime. */
    private function minutesSinceLastMessage(AgentTaskSession $session): ?int
    {
        if ($session->last_message_at === null) {
            return null;
        }

        return max(0, intdiv(Carbon::now()->getTimestampMs() - $session->last_message_at, 60_000));
    }
}
