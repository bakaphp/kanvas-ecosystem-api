<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Mcp\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Kanvas\Connectors\Mcp\Exceptions\McpAuthException;
use Kanvas\Connectors\Mcp\Jobs\RefreshMcpToolSnapshotJob;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\NervousSystem\Capability\Models\McpToolSnapshot;
use Kanvas\Workflow\Models\Integrations;
use Throwable;

/**
 * Two-tier descriptor cache for one agent's connection: Redis is the hot path, the DB snapshot the system
 * of record. Per agent, because what a server exposes depends on whose credential asks.
 *
 * Redis alone would be wrong: a flush or a cold deploy sends every turn at the vendor at once, and if the
 * vendor is down right then every agent silently holds zero tools. With the DB tier a cold Redis costs a
 * query, not an outage.
 */
class McpToolCacheService
{
    /** Past this, serve the stale list and revalidate behind the turn. */
    private const int SOFT_TTL_SECONDS = 3600;

    /** Redis entry lifetime; the DB snapshot outlives it and has no expiry. */
    private const int HARD_TTL_SECONDS = 86400;

    /** How long a failing server is left alone, so one bad vendor does not tax every turn. */
    private const int BREAKER_TTL_SECONDS = 60;

    private const int LOCK_SECONDS = 15;

    public function __construct(
        private readonly Agent $agent,
        private readonly Integrations $integration,
        private readonly string $toolVersion = '1.0.0',
        private ?McpConnectionService $connection = null,
    ) {
    }

    /**
     * Never throws: a turn losing one toolset must not lose the turn.
     *
     * @return list<array<string, mixed>>
     */
    public function descriptors(): array
    {
        $cached = $this->fromRedis();

        if ($cached !== null) {
            $this->revalidateIfSoftStale($cached['fetched_at'] ?? null);

            return $cached['descriptors'];
        }

        $snapshot = $this->snapshot();

        if ($snapshot !== null) {
            $descriptors = $this->normalize($snapshot->payload);
            $this->toRedis($descriptors, $snapshot->fetched_at);
            $this->revalidateIfSoftStale($snapshot->fetched_at?->toIso8601String());

            return $descriptors;
        }

        if ($this->breakerIsOpen()) {
            return [];
        }

        try {
            return $this->refresh();
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function refresh(): array
    {
        $lock = Cache::lock($this->lockKey(), self::LOCK_SECONDS);

        // Ten turns on an expired key must not become ten concurrent handshakes at the vendor; whoever
        // loses the lock serves what is already durable.
        if (! $lock->get()) {
            return $this->lastKnownGood();
        }

        try {
            $descriptors = $this->fetch();
            $this->store($descriptors);

            return $descriptors;
        } finally {
            $lock->release();
        }
    }

    /**
     * The durable snapshot is kept: a reconnect is then instantly warm, and a revoked connection is
     * signalled by the grant, never by a missing snapshot.
     */
    public function forget(): void
    {
        Cache::forget($this->cacheKey());
        Cache::forget($this->breakerKey());
    }

    public function snapshot(): ?McpToolSnapshot
    {
        return McpToolSnapshot::query()
            ->where('apps_id', $this->agent->apps_id)
            ->where('companies_id', $this->agent->companies_id)
            ->where('agents_id', $this->agent->getId())
            ->where('integrations_id', $this->integration->getId())
            ->first();
    }

    public function connection(): McpConnectionService
    {
        return $this->connection ??= new McpConnectionService($this->agent, $this->integration);
    }

    /**
     * Only a successful `tools/list` reaches this — a failed fetch must never overwrite a good snapshot.
     *
     * @param list<array<string, mixed>> $descriptors
     */
    public function store(array $descriptors): void
    {
        $hash = McpToolSnapshot::hashFor($descriptors);
        $now = Carbon::now();
        $existing = $this->snapshot();

        if ($existing !== null && $existing->payload_hash === $hash) {
            // Unchanged: move only the freshness stamp, so neither updated_at nor the prompt prefix churns.
            $existing->fetched_at = $now;
            $existing->saveOrFail();
        } else {
            McpToolSnapshot::query()->updateOrCreate(
                [
                    'apps_id' => $this->agent->apps_id,
                    'companies_id' => $this->agent->companies_id,
                    'agents_id' => $this->agent->getId(),
                    'integrations_id' => $this->integration->getId(),
                ],
                [
                    'payload' => $descriptors,
                    'payload_hash' => $hash,
                    'tool_count' => count($descriptors),
                    'fetched_at' => $now,
                ]
            );
        }

        $this->toRedis($descriptors, $now);
        Cache::forget($this->breakerKey());
    }

    public function breakerIsOpen(): bool
    {
        return (bool) Cache::get($this->breakerKey(), false);
    }

    public function openBreaker(): void
    {
        Cache::put($this->breakerKey(), true, self::BREAKER_TTL_SECONDS);
    }

    /**
     * The tool row's version is in the key, so bumping it force-refreshes every agent at once. Static so
     * tests address the entry exactly as production writes it.
     */
    public static function cacheKeyFor(
        int $appsId,
        int $companiesId,
        int $agentsId,
        int $integrationsId,
        string $toolVersion
    ): string {
        return sprintf(
            'mcp:tools:%d:%d:%d:%d:%s',
            $appsId,
            $companiesId,
            $agentsId,
            $integrationsId,
            $toolVersion
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetch(): array
    {
        try {
            return $this->connection()->fetchDescriptors();
        } catch (Throwable $e) {
            // A rejected credential means the capability is gone, not the network — serving a stale list
            // would only produce tool calls that 401 one by one.
            if ($e instanceof McpAuthException) {
                $this->connection()->markFailed($e->getMessage());
            }

            $this->openBreaker();

            throw $e;
        }
    }

    /**
     * Serve-stale while another request refreshes: the tools almost certainly still exist, and an
     * invoke that fails cleanly beats an agent that quietly lost a skill.
     *
     * @return list<array<string, mixed>>
     */
    private function lastKnownGood(): array
    {
        $snapshot = $this->snapshot();

        return $snapshot === null ? [] : $this->normalize($snapshot->payload);
    }

    /**
     * @return array{descriptors: list<array<string, mixed>>, fetched_at: string|null}|null
     */
    private function fromRedis(): ?array
    {
        $cached = Cache::get($this->cacheKey());

        if (! is_array($cached) || ! isset($cached['descriptors'])) {
            return null;
        }

        return [
            'descriptors' => $this->normalize($cached['descriptors']),
            'fetched_at' => isset($cached['fetched_at']) ? (string) $cached['fetched_at'] : null,
        ];
    }

    /**
     * @param list<array<string, mixed>> $descriptors
     */
    private function toRedis(array $descriptors, ?Carbon $fetchedAt): void
    {
        Cache::put(
            $this->cacheKey(),
            [
                'descriptors' => $descriptors,
                'fetched_at' => ($fetchedAt ?? Carbon::now())->toIso8601String(),
            ],
            self::HARD_TTL_SECONDS
        );
    }

    private function revalidateIfSoftStale(?string $fetchedAt): void
    {
        if ($fetchedAt === null) {
            return;
        }

        try {
            if (Carbon::parse($fetchedAt)->gt(Carbon::now()->subSeconds(self::SOFT_TTL_SECONDS))) {
                return;
            }
        } catch (Throwable) {
            return;
        }

        RefreshMcpToolSnapshotJob::dispatch($this->agent, $this->integration, $this->toolVersion);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function normalize(mixed $payload): array
    {
        if (! is_array($payload)) {
            return [];
        }

        return array_values(array_filter($payload, 'is_array'));
    }

    private function cacheKey(): string
    {
        return self::cacheKeyFor(
            $this->agent->apps_id,
            $this->agent->companies_id,
            $this->agent->getId(),
            $this->integration->getId(),
            $this->toolVersion
        );
    }

    private function breakerKey(): string
    {
        return sprintf(
            'mcp:breaker:%d:%d:%d',
            $this->agent->apps_id,
            $this->agent->getId(),
            $this->integration->getId()
        );
    }

    private function lockKey(): string
    {
        return $this->cacheKey() . ':lock';
    }
}
