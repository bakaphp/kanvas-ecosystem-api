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
 * Drives a remote `sqlite3 -readonly -json` query against the agent's
 * state.db over SSH. Two queries, one connection: in-scope sessions, then
 * in-scope messages.
 *
 * Normalization happens here so the DTO is already Laravel-AI-shaped — role
 * mapped to user/assistant/tool_result, system rows dropped, tool rows with
 * content=null and payload in toolResults.
 *
 * On sqlite3 failure (binary missing, db locked, permissions) this throws
 * RuntimeException; the caller falls back to the jsonl reader.
 */
final class HermesStateDbReaderService implements SessionTranscriptReader
{
    use ScalarCoercionTrait;

    // 36h overlap is safe with deterministic ids absorbing replays — covers
    // a few missed hourly cron runs without ever double-inserting.
    private const int DEFAULT_LOOKBACK_HOURS = 36;

    private const string SOURCE_TAG = 'state.db';

    public function __construct(
        private readonly SshClient $ssh,
    ) {
    }

    #[Override]
    public function read(AgentDeployment $deployment, ?Carbon $since = null): iterable
    {
        $dbPath = $this->resolveDbPath($deployment);
        $sinceEpoch = (int) ($since ?? Carbon::now()->subHours(self::DEFAULT_LOOKBACK_HOURS))->timestamp;

        $sessionsByKey = $this->fetchSessions($dbPath, $sinceEpoch);
        if ($sessionsByKey === []) {
            return;
        }

        $messagesBySession = $this->fetchMessages($dbPath, $sinceEpoch);

        foreach ($sessionsByKey as $sessionId => $rawSession) {
            yield $this->buildTranscript(
                $rawSession,
                $messagesBySession[$sessionId] ?? [],
            );
        }
    }

    private function resolveDbPath(AgentDeployment $deployment): string
    {
        // home_directory is typed non-null but can be empty on legacy rows.
        $home = $deployment->home_directory !== ''
            ? $deployment->home_directory
            : '/home/' . $deployment->system_user;

        return rtrim($home, '/') . '/.hermes/state.db';
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function fetchSessions(string $dbPath, int $sinceEpoch): array
    {
        $sql = <<<SQL
SELECT
    id, source, model, system_prompt, parent_session_id,
    started_at, ended_at, end_reason,
    message_count, tool_call_count,
    input_tokens, output_tokens, cache_read_tokens, cache_write_tokens, reasoning_tokens,
    estimated_cost_usd, actual_cost_usd, title, handoff_state
FROM sessions
WHERE id IN (SELECT DISTINCT session_id FROM messages WHERE timestamp > {$sinceEpoch})
SQL;

        $rows = $this->runSqliteJson($dbPath, $sql);

        $byKey = [];
        foreach ($rows as $row) {
            $byKey[(string) $row['id']] = $row;
        }

        return $byKey;
    }

    /**
     * @return array<string, list<array<string, mixed>>>
     */
    private function fetchMessages(string $dbPath, int $sinceEpoch): array
    {
        $sql = <<<SQL
SELECT
    id, session_id, role, content, tool_call_id, tool_calls, tool_name,
    timestamp, token_count, finish_reason,
    reasoning_content, reasoning_details,
    codex_reasoning_items, codex_message_items
FROM messages
WHERE timestamp > {$sinceEpoch} AND role != 'session_meta' AND role != 'system'
ORDER BY session_id, id
SQL;

        $rows = $this->runSqliteJson($dbPath, $sql);

        $byKey = [];
        foreach ($rows as $row) {
            $byKey[(string) $row['session_id']][] = $row;
        }

        return $byKey;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function runSqliteJson(string $dbPath, string $sql): array
    {
        $escapedDb = escapeshellarg($dbPath);
        // -bail exits on first error so we don't parse half a failed query.
        $singleLine = preg_replace('/\s+/', ' ', $sql) ?? $sql;
        $escapedSql = escapeshellarg(trim($singleLine));

        $output = $this->ssh->exec(
            "sqlite3 -readonly -bail -json {$escapedDb} {$escapedSql} 2>&1",
            120,
        );

        $output = trim($output);
        if ($output === '') {
            return [];
        }

        try {
            $decoded = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException(
                'Hermes state.db query failed (non-json output): ' . substr($output, 0, 200),
                previous: $e,
            );
        }

        if (! is_array($decoded)) {
            throw new RuntimeException('Hermes state.db query returned non-array json.');
        }

        /** @var list<array<string, mixed>> $decoded */
        return $decoded;
    }

    /**
     * @param array<string, mixed> $rawSession
     * @param list<array<string, mixed>> $rawMessages
     */
    private function buildTranscript(array $rawSession, array $rawMessages): ParsedSessionTranscript
    {
        $messages = [];
        foreach ($rawMessages as $row) {
            $messages[] = $this->buildMessage($row);
        }

        return new ParsedSessionTranscript(
            sessionId: (string) $rawSession['id'],
            title: $this->stringOrNull($rawSession['title'] ?? null),
            source: $this->stringOrNull($rawSession['source'] ?? null),
            model: $this->stringOrNull($rawSession['model'] ?? null),
            systemPrompt: $this->stringOrNull($rawSession['system_prompt'] ?? null),
            parentSessionId: $this->stringOrNull($rawSession['parent_session_id'] ?? null),
            startedAt: $this->epochOrNull($rawSession['started_at'] ?? null),
            endedAt: $this->epochOrNull($rawSession['ended_at'] ?? null),
            endReason: $this->stringOrNull($rawSession['end_reason'] ?? null),
            messageCount: $this->intOrNull($rawSession['message_count'] ?? null),
            toolCallCount: $this->intOrNull($rawSession['tool_call_count'] ?? null),
            inputTokens: $this->intOrNull($rawSession['input_tokens'] ?? null),
            outputTokens: $this->intOrNull($rawSession['output_tokens'] ?? null),
            cacheReadTokens: $this->intOrNull($rawSession['cache_read_tokens'] ?? null),
            cacheWriteTokens: $this->intOrNull($rawSession['cache_write_tokens'] ?? null),
            reasoningTokens: $this->intOrNull($rawSession['reasoning_tokens'] ?? null),
            estimatedCostUsd: $this->floatOrNull($rawSession['estimated_cost_usd'] ?? null),
            actualCostUsd: $this->floatOrNull($rawSession['actual_cost_usd'] ?? null),
            handoffState: $this->stringOrNull($rawSession['handoff_state'] ?? null),
            sourceReader: self::SOURCE_TAG,
            messages: $messages,
            rawMeta: [],
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function buildMessage(array $row): ParsedMessage
    {
        $role = $this->normalizeRole((string) ($row['role'] ?? 'user'));
        $content = $row['content'] ?? null;
        if ($content !== null && ! is_string($content)) {
            $content = (string) $content;
        }

        $toolCalls = $this->decodeJsonArray($row['tool_calls'] ?? null);
        // OpenAI/Hermes ship `arguments` as a JSON-encoded string per tool_call;
        // Laravel AI's ToolCall::__construct requires an array. Decode in place.
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
        $toolCallId = $this->stringOrNull($row['tool_call_id'] ?? null);
        $toolName = $this->stringOrNull($row['tool_name'] ?? null);

        $toolResults = null;
        if ($role === 'tool_result') {
            // Match Laravel AI's ToolResult shape (id/name/arguments/result/result_id)
            // and null the content per the ToolResultMessage invariant.
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
            runtimeMessageId: (int) ($row['id'] ?? 0),
            role: $role,
            content: $content,
            toolCalls: $toolCalls,
            toolResults: $toolResults,
            toolCallId: $toolCallId,
            toolName: $toolName,
            finishReason: $this->stringOrNull($row['finish_reason'] ?? null),
            tokenCount: $this->intOrNull($row['token_count'] ?? null),
            reasoningContent: $this->stringOrNull($row['reasoning_content'] ?? null),
            reasoningDetails: $this->decodeJsonAssoc($row['reasoning_details'] ?? null),
            codexReasoningItems: $this->decodeJsonArray($row['codex_reasoning_items'] ?? null),
            codexMessageItems: $this->decodeJsonArray($row['codex_message_items'] ?? null),
            occurredAt: $this->epochOrNull($row['timestamp'] ?? null) ?? Carbon::now(),
            extraMeta: [],
        );
    }

    private function normalizeRole(string $rawRole): string
    {
        return match ($rawRole) {
            'user' => 'user',
            'assistant' => 'assistant',
            'tool', 'function' => 'tool_result',
            // SQL filters session_meta + system; defensive default so an
            // unknown future role still produces a valid Laravel AI Message.
            default => 'assistant',
        };
    }
}
