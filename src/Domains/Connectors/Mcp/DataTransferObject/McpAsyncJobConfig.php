<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Mcp\DataTransferObject;

use Baka\Support\Str;
use Kanvas\Workflow\Models\Integrations;

/**
 * How to follow one MCP tool that starts a job, read from `integrations.metadata.async_jobs.{remote tool}`:
 *
 *     "run_session": {"status_tool": "get_session", "id_field": "session_id", "status_field": "status",
 *                     "done_statuses": ["idle", "stopped", "timed_out", "error"], "live_url_field": "live_url"}
 *
 * `id_field` names the job id in the start tool's result AND the status tool's argument.
 */
final readonly class McpAsyncJobConfig
{
    public const int DEFAULT_POLL_SECONDS = 20;

    public const int DEFAULT_TIMEOUT_SECONDS = 3600;

    /**
     * @param list<string> $doneStatuses
     */
    public function __construct(
        public string $statusTool,
        public string $idField,
        public string $statusField,
        public array $doneStatuses,
        public ?string $liveUrlField = null,
        public int $pollSeconds = self::DEFAULT_POLL_SECONDS,
        public int $timeoutSeconds = self::DEFAULT_TIMEOUT_SECONDS,
    ) {
    }

    /**
     * A block missing any of the fields needed to follow the job is ignored rather than half-applied —
     * a hand-off the poll cannot finish would strand the agent.
     *
     * @param array<string, mixed> $block
     */
    public static function fromArray(array $block): ?self
    {
        $statusTool = Str::trimmedStringOrNull($block['status_tool'] ?? null);
        $idField = Str::trimmedStringOrNull($block['id_field'] ?? null);
        $doneStatuses = array_values(array_filter(
            array_map('strval', (array) ($block['done_statuses'] ?? [])),
            static fn (string $status): bool => $status !== ''
        ));

        if ($statusTool === null || $idField === null || $doneStatuses === []) {
            return null;
        }

        return new self(
            statusTool: $statusTool,
            idField: $idField,
            statusField: Str::trimmedStringOrNull($block['status_field'] ?? null) ?? 'status',
            doneStatuses: $doneStatuses,
            liveUrlField: Str::trimmedStringOrNull($block['live_url_field'] ?? null),
            pollSeconds: max(5, (int) ($block['poll_seconds'] ?? self::DEFAULT_POLL_SECONDS)),
            timeoutSeconds: max(60, (int) ($block['timeout_seconds'] ?? self::DEFAULT_TIMEOUT_SECONDS)),
        );
    }

    /**
     * Read from the server row on every check rather than frozen on the job, so a changed poll interval
     * or status list applies to jobs already in flight.
     */
    public static function forJob(?Integrations $integration, string $remoteToolName): ?self
    {
        return $integration !== null
            ? McpServerConfig::fromIntegration($integration)->asyncJob($remoteToolName)
            : null;
    }

    public function isDone(?string $status): bool
    {
        return $status !== null && in_array($status, $this->doneStatuses, true);
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function jobIdFrom(array $payload): ?string
    {
        return $this->scalar($payload[$this->idField] ?? null);
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function statusFrom(array $payload): ?string
    {
        return $this->scalar($payload[$this->statusField] ?? null);
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function liveUrlFrom(array $payload): ?string
    {
        if ($this->liveUrlField === null) {
            return null;
        }

        $url = $this->scalar($payload[$this->liveUrlField] ?? null);

        return $url !== null && str_starts_with($url, 'https://') ? $url : null;
    }

    /** A job id or status the vendor sent as a number is still one — everything else is not a value. */
    private function scalar(mixed $value): ?string
    {
        return Str::trimmedStringOrNull(is_int($value) ? (string) $value : $value);
    }
}
