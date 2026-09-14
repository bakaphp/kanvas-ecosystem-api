<?php

declare(strict_types=1);

namespace Kanvas\Connectors\ClaudeAgent\Services;

use Kanvas\Connectors\ClaudeAgent\Client;
use Kanvas\Connectors\ClaudeAgent\DataTransferObject\DrainResult;
use Kanvas\Connectors\ClaudeAgent\Enums\DrainOutcomeEnum;

/**
 * Polls a session's event history until the turn reaches a terminal state, and accumulates the
 * agent's text along the way.
 *
 * **The terminal gate is the whole point of this class, and it is easy to get wrong.**
 * `session.status_idle` alone does NOT mean the turn finished — a session goes idle transiently
 * whenever it is waiting on the client (a custom tool result, a tool confirmation). Breaking on
 * bare idle returns an empty or half-written reply as if it were the answer. The gate is:
 *
 *   session.status_terminated                                  -> stop
 *   session.status_idle && stop_reason !== 'requires_action'    -> stop
 *   session.status_idle && stop_reason === 'requires_action'    -> the session is blocked on US
 *
 * Deadline is tracked with `hrtime()` at the loop level, not an HTTP timeout: PHP's socket timeouts
 * are per-chunk and reset on every byte, so they are not a wall clock.
 */
class EventDrainService
{
    public const int DEFAULT_DEADLINE_MS = 120_000;
    public const int DEFAULT_POLL_INTERVAL_MS = 1_500;

    /** @var list<string> */
    protected array $textParts = [];

    /** @var array<string, array{id: string, name: string, input: array<string, mixed>}> */
    protected array $customToolCalls = [];

    /** @var list<array{id: string, name: string, input: array<string, mixed>}> */
    protected array $pendingToolCalls = [];

    protected ?string $cursor;
    protected bool $cursorReached;
    protected ?string $stopReason = null;
    protected ?array $usage = null;
    protected ?DrainOutcomeEnum $outcome = null;

    public function __construct(
        protected readonly Client $client,
        protected readonly string $sessionId,
        ?string $cursor = null,
        protected readonly int $deadlineMs = self::DEFAULT_DEADLINE_MS,
        protected readonly int $pollIntervalMs = self::DEFAULT_POLL_INTERVAL_MS,
    ) {
        $this->cursor = $cursor;
        // With no cursor every event is new; with one we skip forward to it first.
        $this->cursorReached = $cursor === null || $cursor === '';
    }

    public function drain(): DrainResult
    {
        $deadline = hrtime(true) + ($this->deadlineMs * 1_000_000);

        do {
            $this->consumeAllPages();

            if ($this->outcome !== null) {
                break;
            }

            if (hrtime(true) >= $deadline) {
                $this->outcome = DrainOutcomeEnum::TIMED_OUT;

                break;
            }

            $this->sleep();
        } while (true);

        return new DrainResult(
            text: trim(implode("\n\n", $this->textParts)),
            outcome: $this->outcome ?? DrainOutcomeEnum::TIMED_OUT,
            cursor: $this->cursor,
            stopReason: $this->stopReason,
            pendingToolCalls: $this->pendingToolCalls,
            usage: $this->usage,
        );
    }

    /**
     * Walk every page of history each pass. The API exposes no "events since X" filter, so the
     * cursor is applied client-side by skipping forward to the last id we already consumed.
     */
    protected function consumeAllPages(): void
    {
        // Re-arm the skip on every pass. The cursor advances as we consume, and each poll re-reads
        // the whole history — without this the same events are collected again on the next pass and
        // the reply repeats itself once per poll iteration.
        $this->cursorReached = $this->cursor === null || $this->cursor === '';

        $page = null;

        do {
            $response = $this->client->listSessionEvents($this->sessionId, $page);

            foreach ($response['data'] ?? [] as $event) {
                if (! is_array($event)) {
                    continue;
                }

                $this->consume($event);

                if ($this->outcome !== null) {
                    return;
                }
            }

            $page = $response['next_page'] ?? null;
        } while (is_string($page) && $page !== '');
    }

    /**
     * @param array<string, mixed> $event
     */
    protected function consume(array $event): void
    {
        $id = isset($event['id']) ? (string) $event['id'] : null;

        if (! $this->cursorReached) {
            // Still replaying history we've already returned to the user on an earlier turn.
            if ($id !== null && $id === $this->cursor) {
                $this->cursorReached = true;
            }

            return;
        }

        if ($id !== null && $id !== '') {
            $this->cursor = $id;
        }

        match ((string) ($event['type'] ?? '')) {
            'agent.message' => $this->collectText($event),
            'agent.custom_tool_use' => $this->recordToolCall($event, $id),
            'session.usage' => $this->recordUsage($event),
            'session.status_terminated' => $this->finish(DrainOutcomeEnum::TERMINATED),
            'session.status_idle' => $this->handleIdle($event),
            default => null,
        };
    }

    /**
     * Cumulative for the session, so the newest one wins rather than accumulating. One is emitted
     * immediately before every idle event, whatever the stop reason, so a terminal drain always
     * carries the final figure.
     *
     * @param array<string, mixed> $event
     */
    protected function recordUsage(array $event): void
    {
        if (is_array($event['usage'] ?? null)) {
            $this->usage = $event['usage'];
        }
    }

    /**
     * @param array<string, mixed> $event
     */
    protected function handleIdle(array $event): void
    {
        $stopReason = (string) ($event['stop_reason']['type'] ?? '');
        $this->stopReason = $stopReason !== '' ? $stopReason : null;

        if ($stopReason === 'requires_action') {
            $this->pendingToolCalls = $this->blockingToolCalls($event['stop_reason']['event_ids'] ?? null);
            $this->finish(DrainOutcomeEnum::AWAITING_CLIENT);

            return;
        }

        // Only `end_turn` is success. `retries_exhausted` — or any state this client doesn't know
        // yet — must NOT default to COMPLETED, or whatever text happened to be collected (often
        // nothing) gets handed back as though it were the answer.
        $this->finish(match ($stopReason) {
            'end_turn' => DrainOutcomeEnum::COMPLETED,
            'budget_reached' => DrainOutcomeEnum::BUDGET_REACHED,
            default => DrainOutcomeEnum::FAILED,
        });
    }

    /**
     * Blocking ids ride the stop_reason, not the idle event. Anything we never saw as a custom tool
     * call is something we can't satisfy (a tool confirmation, a tool we never declared), so it is
     * dropped here — the caller then sees an empty list and reports that instead of looping.
     *
     * @return list<array{id: string, name: string, input: array<string, mixed>}>
     */
    protected function blockingToolCalls(mixed $eventIds): array
    {
        if (! is_array($eventIds)) {
            return array_values($this->customToolCalls);
        }

        $wanted = array_flip(array_map(strval(...), $eventIds));

        return array_values(array_intersect_key($this->customToolCalls, $wanted));
    }

    /**
     * A custom tool call arrives as its own event *before* the idle that blocks on it, so we bank
     * the details here and match them up when the stop reason names their ids.
     *
     * @param array<string, mixed> $event
     */
    protected function recordToolCall(array $event, ?string $eventId): void
    {
        $name = (string) ($event['name'] ?? '');

        if ($eventId === null || $eventId === '' || $name === '') {
            return;
        }

        $input = $event['input'] ?? [];

        $this->customToolCalls[$eventId] = [
            'id' => $eventId,
            'name' => $name,
            'input' => is_array($input) ? $input : [],
        ];
    }

    /**
     * @param array<string, mixed> $event
     */
    protected function collectText(array $event): void
    {
        foreach ($event['content'] ?? [] as $block) {
            if (is_array($block) && ($block['type'] ?? null) === 'text') {
                $text = trim((string) ($block['text'] ?? ''));

                if ($text !== '') {
                    $this->textParts[] = $text;
                }
            }
        }
    }

    protected function finish(DrainOutcomeEnum $outcome): void
    {
        $this->outcome = $outcome;
    }

    protected function sleep(): void
    {
        if ($this->pollIntervalMs > 0) {
            usleep($this->pollIntervalMs * 1_000);
        }
    }
}
