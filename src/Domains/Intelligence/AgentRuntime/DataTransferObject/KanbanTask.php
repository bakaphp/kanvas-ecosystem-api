<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\AgentRuntime\DataTransferObject;

use Baka\Traits\ScalarCoercionTrait;
use Kanvas\Intelligence\AgentRuntime\Enums\KanbanStatusEnum;
use Spatie\LaravelData\Data;

/**
 * Provider-agnostic snapshot of one kanban task, normalized from a runtime's board.
 *
 * The tree is edge-based: roots have no parents. The worker handoff (summary + structured
 * metadata) lives in the runtime's run history, NOT a `result` field — Hermes verified: the
 * task `result` column stays null while output lands in `task_runs.summary`/`metadata`.
 *
 * The `parse*` factories are deliberately NOT named `from*`: under strict_types Spatie's
 * `::from()` routing can't be trusted to coerce raw JSON primitives, and two `from(array)`
 * candidates would be ambiguous — so we parse explicitly here.
 */
final class KanbanTask extends Data
{
    use ScalarCoercionTrait;

    /**
     * @param list<string> $parentIds
     * @param list<string> $childIds
     * @param array<array-key, mixed>|null $metadata latest run's structured handoff (changed_files, sources, …)
     * @param list<array{author: string, body: string, createdAt: int|null}> $comments oldest-first thread
     */
    public function __construct(
        public readonly string $id,
        public readonly string $title,
        public readonly ?string $body,
        public readonly ?string $assignee,
        public readonly KanbanStatusEnum $status,
        public readonly int $priority,
        public readonly ?string $tenant,
        public readonly array $parentIds,
        public readonly array $childIds,
        public readonly ?string $latestSummary,
        public readonly ?array $metadata,
        public readonly ?int $createdAt,
        public readonly ?int $startedAt,
        public readonly ?int $completedAt,
        public readonly array $comments = [],
    ) {
    }

    public function isRoot(): bool
    {
        return $this->parentIds === [];
    }

    /**
     * Build from a `show --json` payload: { task: {...}, parents: [...], children: [...],
     * latest_summary: ?string, runs: [{ metadata: {...} }, ...] }.
     *
     * @param array<array-key, mixed> $payload
     */
    public static function parseShowPayload(array $payload): self
    {
        $task = isset($payload['task']) && is_array($payload['task']) ? $payload['task'] : $payload;

        return new self(
            id: (string) ($task['id'] ?? ''),
            title: (string) ($task['title'] ?? ''),
            body: self::nullableString($task['body'] ?? null),
            assignee: self::nullableString($task['assignee'] ?? null),
            status: KanbanStatusEnum::fromRuntime(isset($task['status']) ? (string) $task['status'] : null),
            priority: (int) ($task['priority'] ?? 0),
            tenant: self::nullableString($task['tenant'] ?? null),
            parentIds: self::stringList($payload['parents'] ?? []),
            childIds: self::stringList($payload['children'] ?? []),
            latestSummary: self::nullableString($payload['latest_summary'] ?? null),
            metadata: self::latestRunMetadata($payload['runs'] ?? []),
            createdAt: self::nullableInt($task['created_at'] ?? null),
            startedAt: self::nullableInt($task['started_at'] ?? null),
            completedAt: self::nullableInt($task['completed_at'] ?? null),
            comments: self::parseComments($payload['comments'] ?? []),
        );
    }

    /**
     * Build from a flat task row (the `create`/`list` shape — no edges, no handoff).
     *
     * @param array<array-key, mixed> $row
     */
    public static function parseFlatRow(array $row): self
    {
        return new self(
            id: (string) ($row['id'] ?? ''),
            title: (string) ($row['title'] ?? ''),
            body: self::nullableString($row['body'] ?? null),
            assignee: self::nullableString($row['assignee'] ?? null),
            status: KanbanStatusEnum::fromRuntime(isset($row['status']) ? (string) $row['status'] : null),
            priority: (int) ($row['priority'] ?? 0),
            tenant: self::nullableString($row['tenant'] ?? null),
            parentIds: [],
            childIds: [],
            latestSummary: null,
            metadata: null,
            createdAt: self::nullableInt($row['created_at'] ?? null),
            startedAt: self::nullableInt($row['started_at'] ?? null),
            completedAt: self::nullableInt($row['completed_at'] ?? null),
        );
    }

    /**
     * Normalize the `show --json` comments array. Hermes shape is `{author, body, created_at}`
     * (no comment id); we accept `text`/`author_name` aliases defensively. Rows without a body
     * are dropped. Watermark dedup downstream keys on `createdAt` (epoch) since there's no id.
     *
     * @return list<array{author: string, body: string, createdAt: int|null}>
     */
    private static function parseComments(mixed $comments): array
    {
        if (! is_array($comments)) {
            return [];
        }

        $parsed = [];

        foreach ($comments as $comment) {
            if (! is_array($comment)) {
                continue;
            }

            $body = self::nullableString($comment['body'] ?? $comment['text'] ?? null);
            if ($body === null) {
                continue;
            }

            $parsed[] = [
                'author' => self::nullableString($comment['author'] ?? $comment['author_name'] ?? null) ?? '',
                'body' => $body,
                'createdAt' => self::nullableInt($comment['created_at'] ?? null),
            ];
        }

        return $parsed;
    }

    /**
     * Newest run carrying structured metadata wins (handoff for the next reader).
     *
     * @return array<array-key, mixed>|null
     */
    private static function latestRunMetadata(mixed $runs): ?array
    {
        if (! is_array($runs)) {
            return null;
        }

        $metadata = null;

        foreach ($runs as $run) {
            if (is_array($run) && isset($run['metadata']) && is_array($run['metadata'])) {
                $metadata = $run['metadata'];
            }
        }

        return $metadata;
    }
}
