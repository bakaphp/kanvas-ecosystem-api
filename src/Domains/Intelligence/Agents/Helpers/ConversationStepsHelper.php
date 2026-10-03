<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Helpers;

use JsonException;

/**
 * Builds the Laravel AI 1.x `steps` value of an assistant row: one entry per model round trip, each
 * tool result stored on the call that produced it. Every writer of agent_conversation_messages — the
 * laravel store, Neuron history, runtime transcript ingest, the backfill — goes through here so the
 * column has one shape whatever produced the turn.
 */
final class ConversationStepsHelper
{
    /**
     * The `steps` value for a stored row, whatever wrote it. A human's prompt has none. Tool results are
     * not always on an assistant row — Neuron's `ToolResultMessage` is a `UserMessage`, so its row is
     * `user` with `tool_results` set, and runtime ingest uses `tool_result` — and those rows still need
     * their result folded onto the call, with no step text (the content, when any, IS the result).
     *
     * @param array<int, mixed> $toolCalls
     * @param array<int, mixed> $toolResults
     *
     * @return list<array<string, mixed>>
     */
    public static function forRow(
        string $role,
        string $content,
        array $toolCalls,
        array $toolResults,
        string $reasoning = '',
    ): array {
        if ($role === 'user' && $toolCalls === [] && $toolResults === []) {
            return [];
        }

        return self::fromToolCallsAndResults(
            $role === 'assistant' ? $content : '',
            $toolCalls,
            $toolResults,
            $reasoning,
        );
    }

    /**
     * Mirrors the package's own backfill: a turn that both called tools and answered is two steps, the
     * calls first, so a replay sends the results before the text that used them.
     *
     * @param array<int, mixed> $toolCalls
     * @param array<int, mixed> $toolResults
     *
     * @return list<array<string, mixed>>
     */
    public static function fromToolCallsAndResults(
        string $content,
        array $toolCalls,
        array $toolResults,
        string $reasoning = '',
    ): array {
        $calls = self::foldResultsIntoCalls($toolCalls, $toolResults);

        if ($calls !== [] && $content !== '') {
            return [self::step('', $calls), self::step($content, [], $reasoning)];
        }

        return [self::step($content, $calls, $reasoning)];
    }

    /**
     * A result whose call sits on another row — Hermes and Neuron write the call and its result as two
     * messages — is kept as a call that already carries its result, so nothing a replay needs is lost.
     *
     * @param array<int, mixed> $toolCalls
     * @param array<int, mixed> $toolResults
     *
     * @return list<array<string, mixed>>
     */
    public static function foldResultsIntoCalls(array $toolCalls, array $toolResults): array
    {
        $pending = [];
        foreach ($toolResults as $result) {
            if (! is_array($result)) {
                continue;
            }

            $normalized = self::normalizeCall($result);
            $pending[self::matchKey($normalized)][] = $normalized;
        }

        $calls = [];
        foreach ($toolCalls as $call) {
            if (! is_array($call)) {
                continue;
            }

            $normalized = self::normalizeCall($call);
            $key = self::matchKey($normalized);
            $result = isset($pending[$key]) ? array_shift($pending[$key]) : null;

            if ($result !== null) {
                $normalized = [...$normalized, ...array_intersect_key($result, array_flip(['result', 'denied', 'failed']))];
            }

            $calls[] = $normalized;
        }

        foreach ($pending as $orphans) {
            foreach ($orphans as $orphan) {
                $calls[] = $orphan;
            }
        }

        return $calls;
    }

    /**
     * Laravel AI (`id`/`arguments`), Neuron (`callId`/`inputs`), OpenAI-shaped runtimes (`function.name`,
     * `function.arguments` as a JSON string) spell the same three fields differently.
     *
     * @param array<string, mixed> $call
     *
     * @return array<string, mixed>
     */
    public static function normalizeCall(array $call): array
    {
        $function = is_array($call['function'] ?? null) ? $call['function'] : [];

        $normalized = [
            'id' => (string) ($call['id'] ?? $call['callId'] ?? $call['call_id'] ?? $call['tool_call_id'] ?? ''),
            'name' => (string) ($call['name'] ?? $function['name'] ?? ''),
            'arguments' => self::arguments($call['arguments'] ?? $function['arguments'] ?? $call['inputs'] ?? $call['input'] ?? []),
        ];

        foreach (['result', 'denied', 'failed', 'approval_reason'] as $key) {
            if (isset($call[$key])) {
                $normalized[$key] = $call[$key];
            }
        }

        return $normalized;
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function arguments(mixed $arguments): array
    {
        if (is_string($arguments)) {
            try {
                $arguments = json_decode($arguments, true, 64, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                return [];
            }
        }

        if (is_object($arguments)) {
            $arguments = (array) $arguments;
        }

        return is_array($arguments) ? $arguments : [];
    }

    /**
     * @param array<string, mixed> $call
     */
    private static function matchKey(array $call): string
    {
        return $call['id'] !== '' ? 'id:' . $call['id'] : 'name:' . $call['name'];
    }

    /**
     * @param list<array<string, mixed>> $calls
     *
     * @return array<string, mixed>
     */
    private static function step(string $content, array $calls, string $reasoning = ''): array
    {
        return [
            'content' => $content,
            'tool_calls' => $calls,
            'reasoning' => $reasoning,
            'replay_blocks' => [],
            'provider_tool_calls' => [],
        ];
    }
}
