<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Hermes\Services;

use Baka\Traits\ScalarCoercionTrait;
use Illuminate\Support\Carbon;
use JsonException;
use Kanvas\Intelligence\AgentRuntime\Contracts\SessionTranscriptReader;
use Kanvas\Intelligence\AgentRuntime\DataTransferObject\ParsedMessage;
use Kanvas\Intelligence\AgentRuntime\DataTransferObject\ParsedSessionTranscript;
use Kanvas\Intelligence\AgentRuntime\SshClient;
use Kanvas\Intelligence\Agents\Models\AgentDeployment;
use Override;
use RuntimeException;

/**
 * Engaged when the primary state.db path is unreachable (locked, missing,
 * schema-version drift). Parses per-session .jsonl files instead — same DTO
 * shape, fewer fields populated (no reasoning_*, model, real titles, costs).
 * sourceReader='jsonl' tags rows so a later state.db run can re-enrich.
 */
final class HermesJsonlFallbackReaderService implements SessionTranscriptReader
{
    use ScalarCoercionTrait;

    private const int DEFAULT_LOOKBACK_HOURS = 36;

    private const string SOURCE_TAG = 'jsonl';

    public function __construct(
        private readonly SshClient $ssh,
    ) {
    }

    #[Override]
    public function read(AgentDeployment $deployment, ?Carbon $since = null): iterable
    {
        $sessionsDir = $this->resolveSessionsDir($deployment);
        $sinceEpoch = (int) ($since ?? Carbon::now()->subHours(self::DEFAULT_LOOKBACK_HOURS))->timestamp;

        $index = $this->loadIndex($sessionsDir);
        if ($index === []) {
            return;
        }

        foreach ($index as $entry) {
            $sessionId = (string) ($entry['session_id'] ?? '');
            if ($sessionId === '') {
                continue;
            }

            $lastActive = $this->parseIso($entry['updated_at'] ?? null);
            if ($lastActive !== null && $lastActive->timestamp < $sinceEpoch) {
                continue;
            }

            yield $this->buildTranscript($sessionsDir, $sessionId, $entry, $sinceEpoch);
        }
    }

    private function resolveSessionsDir(AgentDeployment $deployment): string
    {
        $home = $deployment->home_directory !== ''
            ? $deployment->home_directory
            : '/home/' . $deployment->system_user;

        return rtrim($home, '/') . '/.hermes/sessions';
    }

    /**
     * @return list<array<array-key, mixed>>
     */
    private function loadIndex(string $sessionsDir): array
    {
        $raw = $this->ssh->readFile($sessionsDir . '/sessions.json');
        if (trim($raw) === '') {
            return [];
        }

        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException('Failed to parse Hermes sessions.json', previous: $e);
        }

        if (! is_array($decoded)) {
            return [];
        }

        // sessions.json is a map keyed by session_key; we only need the row values.
        return array_values(array_filter(
            $decoded,
            static fn (mixed $v): bool => is_array($v),
        ));
    }

    /**
     * @param array<array-key, mixed> $indexEntry
     */
    private function buildTranscript(
        string $sessionsDir,
        string $sessionId,
        array $indexEntry,
        int $sinceEpoch,
    ): ParsedSessionTranscript {
        $messages = $this->readMessageFile($sessionsDir, $sessionId, $sinceEpoch);

        return new ParsedSessionTranscript(
            sessionId: $sessionId,
            title: $this->synthesizeTitle($indexEntry),
            source: $this->stringOrNull($indexEntry['platform'] ?? null),
            model: null,
            systemPrompt: null,
            parentSessionId: null,
            startedAt: $this->parseIso($indexEntry['created_at'] ?? null),
            endedAt: $this->parseIso($indexEntry['updated_at'] ?? null),
            endReason: null,
            messageCount: null,
            toolCallCount: null,
            inputTokens: $this->intOrNull($indexEntry['input_tokens'] ?? null),
            outputTokens: $this->intOrNull($indexEntry['output_tokens'] ?? null),
            cacheReadTokens: $this->intOrNull($indexEntry['cache_read_tokens'] ?? null),
            cacheWriteTokens: $this->intOrNull($indexEntry['cache_write_tokens'] ?? null),
            reasoningTokens: null,
            estimatedCostUsd: $this->floatOrNull($indexEntry['estimated_cost_usd'] ?? null),
            actualCostUsd: null,
            handoffState: null,
            sourceReader: self::SOURCE_TAG,
            messages: $messages,
            rawMeta: [
                'session_key' => $indexEntry['session_key'] ?? null,
                'display_name' => $indexEntry['display_name'] ?? null,
                'chat_type' => $indexEntry['chat_type'] ?? null,
                'origin' => $indexEntry['origin'] ?? null,
            ],
        );
    }

    /**
     * @return list<ParsedMessage>
     */
    private function readMessageFile(string $sessionsDir, string $sessionId, int $sinceEpoch): array
    {
        try {
            $raw = $this->ssh->readFile($sessionsDir . '/' . $sessionId . '.jsonl');
        } catch (RuntimeException) {
            return [];
        }

        if (trim($raw) === '') {
            return [];
        }

        $out = [];
        $lineIndex = 0;
        foreach (explode("\n", $raw) as $line) {
            $line = trim($line);
            $lineIndex++;
            if ($line === '') {
                continue;
            }

            try {
                $event = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                continue;
            }

            if (! is_array($event)) {
                continue;
            }

            $role = (string) ($event['role'] ?? '');
            if ($role === 'session_meta' || $role === 'system') {
                continue;
            }

            $ts = $this->parseIso($event['timestamp'] ?? null);
            if ($ts !== null && $ts->timestamp < $sinceEpoch) {
                continue;
            }

            $out[] = $this->buildMessage($event, $lineIndex, $ts);
        }

        return $out;
    }

    /**
     * @param array<array-key, mixed> $event
     */
    private function buildMessage(array $event, int $lineIndex, ?Carbon $ts): ParsedMessage
    {
        $rawRole = (string) ($event['role'] ?? 'user');
        $role = match ($rawRole) {
            'user' => 'user',
            'assistant' => 'assistant',
            'tool', 'function' => 'tool_result',
            default => 'assistant',
        };

        $content = $this->extractContent($event['content'] ?? null);

        $toolCalls = isset($event['tool_calls']) && is_array($event['tool_calls']) && $event['tool_calls'] !== []
            ? array_values($event['tool_calls'])
            : null;
        // Mirror state.db reader: decode `arguments` strings to arrays so
        // KanvasConversationStore can build Laravel AI ToolCall instances.
        if ($toolCalls !== null) {
            foreach ($toolCalls as &$call) {
                if (is_array($call) && isset($call['arguments']) && is_string($call['arguments'])) {
                    try {
                        $decoded = json_decode($call['arguments'], true, 64, JSON_THROW_ON_ERROR);
                        $call['arguments'] = is_array($decoded) ? $decoded : [];
                    } catch (JsonException) {
                        $call['arguments'] = [];
                    }
                }
                if (is_array($call) && (! isset($call['arguments']) || ! is_array($call['arguments']))) {
                    $call['arguments'] = [];
                }
            }
            unset($call);
        }

        $toolCallId = isset($event['tool_call_id']) && is_string($event['tool_call_id']) && $event['tool_call_id'] !== ''
            ? $event['tool_call_id']
            : null;

        $toolName = isset($event['name']) && is_string($event['name']) && $event['name'] !== ''
            ? $event['name']
            : null;

        $toolResults = null;
        if ($role === 'tool_result') {
            $toolResults = [[
                'id' => $toolCallId,
                'name' => $toolName,
                'arguments' => null,
                'result' => $content,
                'result_id' => $toolCallId,
            ]];
            $content = null;
        }

        return new ParsedMessage(
            // No stable sqlite-style id in JSONL; prefix the line index so it
            // can't collide with state.db ids on a re-enrich pass.
            runtimeMessageId: 'jsonl-' . $lineIndex,
            role: $role,
            content: $content,
            toolCalls: $toolCalls,
            toolResults: $toolResults,
            toolCallId: $toolCallId,
            toolName: $toolName,
            finishReason: null,
            tokenCount: null,
            reasoningContent: null,
            reasoningDetails: null,
            codexReasoningItems: null,
            codexMessageItems: null,
            occurredAt: $ts ?? Carbon::now(),
            extraMeta: [],
        );
    }

    /**
     * OpenAI content blocks ship as either a string or a list of {type,text}
     * objects. Join the text fields when it's a list.
     */
    private function extractContent(mixed $raw): ?string
    {
        if (is_string($raw)) {
            return $raw;
        }
        if (! is_array($raw)) {
            return null;
        }
        $joined = '';
        foreach ($raw as $block) {
            if (is_array($block) && isset($block['text']) && is_string($block['text'])) {
                $joined .= $block['text'];
            } elseif (is_string($block)) {
                $joined .= $block;
            }
        }

        return $joined;
    }

    /**
     * @param array<array-key, mixed> $entry
     */
    private function synthesizeTitle(array $entry): string
    {
        $platform = is_string($entry['platform'] ?? null) ? $entry['platform'] : 'unknown';
        $chatType = is_string($entry['chat_type'] ?? null) ? $entry['chat_type'] : 'unknown';
        $name = is_string($entry['display_name'] ?? null) && $entry['display_name'] !== ''
            ? $entry['display_name']
            : 'session';

        return sprintf('hermes:%s:%s:%s', $platform, $chatType, $name);
    }
}
