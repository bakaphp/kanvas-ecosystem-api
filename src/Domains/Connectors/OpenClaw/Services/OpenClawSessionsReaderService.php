<?php

declare(strict_types=1);

namespace Kanvas\Connectors\OpenClaw\Services;

use Baka\Traits\ScalarCoercionTrait;
use Illuminate\Support\Carbon;
use JsonException;
use Kanvas\Connectors\OpenClaw\SshClient;
use Kanvas\Intelligence\AgentRuntime\Contracts\SessionTranscriptReader;
use Kanvas\Intelligence\AgentRuntime\DataTransferObject\ParsedMessage;
use Kanvas\Intelligence\AgentRuntime\DataTransferObject\ParsedSessionTranscript;
use Kanvas\Intelligence\Agents\Models\AgentDeployment;
use Override;
use RuntimeException;

/**
 * OpenClaw stores sessions as per-session JSONL files under
 * `~/.openclaw/agents/<slug>/sessions/<uuid>.jsonl`, alongside auxiliary
 * `.trajectory.jsonl` / `.checkpoint.*.jsonl` / `.jsonl.reset.*` files we
 * deliberately skip — only the bare `<uuid>.jsonl` carries the conversation
 * messages.
 *
 * Each line is a typed event (`type` ∈ {session, model_change,
 * thinking_level_change, message, …}). We only care about `type:"message"`,
 * which wraps an OpenAI-style `{role, content[]}` envelope inside the
 * `.message` field.
 */
final class OpenClawSessionsReaderService implements SessionTranscriptReader
{
    use ScalarCoercionTrait;

    private const string SOURCE_TAG = 'openclaw-jsonl';

    public function __construct(
        private readonly SshClient $ssh,
    ) {
    }

    #[Override]
    public function read(AgentDeployment $deployment, ?Carbon $since = null): iterable
    {
        $sessionsDir = $this->resolveSessionsDir($deployment);
        if ($sessionsDir === null) {
            return;
        }

        $sinceEpoch = $since !== null ? (int) $since->timestamp : 0;

        foreach ($this->listSessionFiles($sessionsDir, $sinceEpoch) as $sessionId => $mtimeEpoch) {
            yield $this->buildTranscript($sessionsDir, $sessionId, $mtimeEpoch, $sinceEpoch);
        }
    }

    private function resolveSessionsDir(AgentDeployment $deployment): ?string
    {
        $agentSlug = $deployment->agent?->slug;
        if (! is_string($agentSlug) || $agentSlug === '') {
            return null;
        }

        $home = $deployment->home_directory !== ''
            ? $deployment->home_directory
            : '/home/' . $deployment->system_user;

        return rtrim($home, '/') . '/.openclaw/agents/' . $agentSlug . '/sessions';
    }

    /**
     * `find -printf "%T@ %f\n"` over the sessions dir, filtering to bare
     * `<uuid>.jsonl` files (excluding `.trajectory.jsonl`,
     * `.checkpoint.*.jsonl`, `.jsonl.reset.*`) modified at-or-after $since.
     *
     * Sudo so we traverse the 0700-owned agent home dir.
     *
     * @return iterable<string, int>  session UUID => mtime epoch
     */
    private function listSessionFiles(string $sessionsDir, int $sinceEpoch): iterable
    {
        $cmd = sprintf(
            "sudo -n find %s -maxdepth 1 -type f -name '*.jsonl' "
            . "! -name '*.trajectory.jsonl' "
            . "! -name '*.checkpoint.*.jsonl' "
            . "-printf '%%T@ %%f\\n' 2>/dev/null",
            escapeshellarg($sessionsDir),
        );

        $output = $this->ssh->exec($cmd, 30);

        foreach (explode("\n", $output) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            // "1716638400.123 02eaf5bc-13fb-4f29-b7b0-7635c697ab6c.jsonl"
            $parts = explode(' ', $line, 2);
            if (count($parts) !== 2) {
                continue;
            }

            $mtime = (int) ((float) $parts[0]);
            if ($sinceEpoch > 0 && $mtime < $sinceEpoch) {
                continue;
            }

            $sessionId = substr($parts[1], 0, -strlen('.jsonl'));
            // Sanity-check UUID-ish length to avoid feeding a reset marker
            // (`<uuid>.jsonl.reset.<ts>`) into persistence.
            if (! preg_match('/^[0-9a-f-]{36}$/i', $sessionId)) {
                continue;
            }

            yield $sessionId => $mtime;
        }
    }

    private function buildTranscript(
        string $sessionsDir,
        string $sessionId,
        int $mtimeEpoch,
        int $sinceEpoch,
    ): ParsedSessionTranscript {
        [$messages, $headerTs, $model] = $this->readMessageFile($sessionsDir, $sessionId, $sinceEpoch);

        // Fall back to file mtime for endedAt when the JSONL has no trailing
        // timestamp we can trust. startedAt comes from the `type:"session"`
        // header line when present.
        $endedAt = Carbon::createFromTimestamp($mtimeEpoch);

        return new ParsedSessionTranscript(
            sessionId: $sessionId,
            title: 'openclaw:session:' . substr($sessionId, 0, 8),
            source: 'openclaw',
            model: $model,
            systemPrompt: null,
            parentSessionId: null,
            startedAt: $headerTs ?? $endedAt,
            endedAt: $endedAt,
            endReason: null,
            messageCount: count($messages),
            toolCallCount: null,
            inputTokens: null,
            outputTokens: null,
            cacheReadTokens: null,
            cacheWriteTokens: null,
            reasoningTokens: null,
            estimatedCostUsd: null,
            actualCostUsd: null,
            handoffState: null,
            sourceReader: self::SOURCE_TAG,
            messages: $messages,
            rawMeta: [],
        );
    }

    /**
     * @return array{0: list<ParsedMessage>, 1: ?Carbon, 2: ?string}
     *         [messages, sessionStartedAt, lastObservedModel]
     */
    private function readMessageFile(string $sessionsDir, string $sessionId, int $sinceEpoch): array
    {
        try {
            $raw = $this->ssh->readFileAsUser($sessionsDir . '/' . $sessionId . '.jsonl');
        } catch (RuntimeException) {
            return [[], null, null];
        }

        if (trim($raw) === '') {
            return [[], null, null];
        }

        $messages = [];
        $headerTs = null;
        $currentModel = null;
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

            $type = (string) ($event['type'] ?? '');

            if ($type === 'session') {
                $headerTs = $this->parseIso($event['timestamp'] ?? null);

                continue;
            }
            if ($type === 'model_change') {
                $currentModel = is_string($event['modelId'] ?? null) ? $event['modelId'] : $currentModel;

                continue;
            }
            if ($type !== 'message') {
                continue;
            }

            $ts = $this->parseIso($event['timestamp'] ?? null);
            if ($ts !== null && $sinceEpoch > 0 && $ts->timestamp < $sinceEpoch) {
                continue;
            }

            $parsed = $this->buildMessage($event, $lineIndex, $ts);
            if ($parsed !== null) {
                $messages[] = $parsed;
            }
        }

        return [$messages, $headerTs, $currentModel];
    }

    /**
     * @param array<array-key, mixed> $event
     */
    private function buildMessage(array $event, int $lineIndex, ?Carbon $ts): ?ParsedMessage
    {
        $envelope = is_array($event['message'] ?? null) ? $event['message'] : null;
        if ($envelope === null) {
            return null;
        }

        $rawRole = (string) ($envelope['role'] ?? '');
        if ($rawRole === 'system' || $rawRole === '') {
            return null;
        }

        $role = match ($rawRole) {
            'user' => 'user',
            'assistant' => 'assistant',
            'toolResult', 'tool', 'function' => 'tool_result',
            default => 'assistant',
        };

        [$text, $toolCalls, $toolResults] = $this->extractContentBlocks(
            $envelope['content'] ?? null,
            $role,
        );

        $messageId = is_string($event['id'] ?? null) && $event['id'] !== ''
            ? (string) $event['id']
            : ('openclaw-' . $lineIndex);

        return new ParsedMessage(
            runtimeMessageId: $messageId,
            role: $role,
            content: $role === 'tool_result' ? null : $text,
            toolCalls: $toolCalls,
            toolResults: $toolResults,
            toolCallId: $role === 'tool_result' ? $this->stringOrNull($envelope['toolCallId'] ?? null) : null,
            toolName: $role === 'tool_result' ? $this->stringOrNull($envelope['toolName'] ?? null) : null,
            finishReason: $this->stringOrNull($envelope['stopReason'] ?? null),
            tokenCount: $this->intOrNull($envelope['usage']['totalTokens'] ?? null),
            reasoningContent: null,
            reasoningDetails: null,
            codexReasoningItems: null,
            codexMessageItems: null,
            occurredAt: $ts ?? Carbon::now(),
            extraMeta: [],
        );
    }

    /**
     * OpenClaw content blocks: list of `{type, ...}` items. Mostly:
     *  - {type:"text", text}                              → message body
     *  - {type:"thinking", thinking, thinkingSignature}   → ignored for transcript
     *  - {type:"toolCall", id, name, arguments}           → tool_calls array
     *
     * For tool_result messages the content is the tool's output text and we
     * package it as a single toolResults entry; the inner `content` field
     * goes to null per the SessionTranscriptReader contract.
     *
     * @return array{0: ?string, 1: ?list<array<string, mixed>>, 2: ?list<array<string, mixed>>}
     */
    private function extractContentBlocks(mixed $raw, string $role): array
    {
        if (! is_array($raw)) {
            $text = is_string($raw) ? $raw : null;

            return [$text, null, null];
        }

        $text = '';
        $toolCalls = [];

        foreach ($raw as $block) {
            if (! is_array($block)) {
                continue;
            }
            $blockType = (string) ($block['type'] ?? '');

            if ($blockType === 'text' && is_string($block['text'] ?? null)) {
                $text .= $block['text'];

                continue;
            }
            if ($blockType === 'toolCall') {
                $toolCalls[] = [
                    'id' => $this->stringOrNull($block['id'] ?? null),
                    'name' => $this->stringOrNull($block['name'] ?? null),
                    'arguments' => is_array($block['arguments'] ?? null) ? $block['arguments'] : [],
                ];
            }
            // `thinking` blocks are intentionally dropped — they're prompt
            // chain-of-thought, not user-visible transcript content.
        }

        if ($role === 'tool_result') {
            $toolResults = [[
                'id' => null,
                'name' => null,
                'arguments' => null,
                'result' => $text === '' ? null : $text,
                'result_id' => null,
            ]];

            return [null, null, $toolResults];
        }

        return [
            $text === '' ? null : $text,
            $toolCalls === [] ? null : $toolCalls,
            null,
        ];
    }

    private function parseIso(mixed $raw): ?Carbon
    {
        if (! is_string($raw) || $raw === '') {
            return null;
        }

        try {
            return Carbon::parse($raw);
        } catch (\Throwable) {
            return null;
        }
    }
}
