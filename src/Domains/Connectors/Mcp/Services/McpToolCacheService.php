<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Mcp\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Mcp\Exceptions\McpAuthException;
use Kanvas\Connectors\Mcp\Jobs\RefreshMcpToolSnapshotJob;
use Kanvas\NervousSystem\Capability\Models\McpToolSnapshot;
use Kanvas\Workflow\Models\Integrations;
use Throwable;

/**
 * Two-tier descriptor cache: Redis is the hot path, the DB snapshot is the system of record.
 *
 * Redis alone would be wrong. A flush, an eviction or a cold deploy sends every agent turn across
 * every company at the vendor simultaneously — and if the vendor happens to be down at that moment,
 * every agent silently holds zero tools. The DB tier means a cold Redis costs a query, not an outage.
 */
class McpToolCacheService
{
    /** Past this, serve the stale list and revalidate behind the turn. */
    private const int SOFT_TTL_SECONDS = 3600;

    /** Redis entry lifetime; the DB snapshot outlives it and has no expiry. */
    private const int HARD_TTL_SECONDS = 86400;

    /** How long a failing server is left alone. One bad vendor must not tax every turn. */
    private const int BREAKER_TTL_SECONDS = 60;

    private const int LOCK_SECONDS = 15;

    private ?McpConnectionService $connection = null;

    public function __construct(
        private readonly Apps $app,
        private readonly Companies $company,
        private readonly Integrations $integration,
        private readonly string $toolVersion = '1.0.0',
        ?McpConnectionService $connection = null,
    ) {
        $this->connection = $connection;
    }

    /**
     * The descriptor list for this company's instance of this server, or `[]` when there is nothing
     * safe to serve. Never throws: a turn losing one toolset must not lose the turn.
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
            // refresh() has already opened the breaker and, where it could, recorded the cause.
            return [];
        }
    }

    /**
     * Force a network read and write both tiers.
     *
     * @return list<array<string, mixed>>
     */
    public function refresh(): array
    {
        $lock = Cache::lock($this->lockKey(), self::LOCK_SECONDS);

        // Ten simultaneous turns on an expired key must not fan out into ten concurrent handshakes at
        // the vendor. Whoever loses the lock falls back to whatever is already durable.
        if (! $lock->get()) {
            return $this->lastKnownGood();
        }

        try {
            $descriptors = $this->connection()->fetchDescriptors();
        } catch (McpAuthException $e) {
            // The credential is dead, not the network. The capability is genuinely gone, so serving a
            // stale list would only produce tool calls that 401 one by one.
            $this->connection()->markFailed();
            $this->openBreaker();
            $lock->release();

            throw $e;
        } catch (Throwable $e) {
            $this->openBreaker();
            $lock->release();

            throw $e;
        }

        $this->store($descriptors);
        $lock->release();

        return $descriptors;
    }

    /**
     * Serve-stale on a fetch failure: the tools almost certainly still exist and the outage is
     * probably transient, so an invoke that fails cleanly beats an agent that quietly lost a skill.
     *
     * @return list<array<string, mixed>>
     */
    public function lastKnownGood(): array
    {
        $snapshot = $this->snapshot();

        return $snapshot === null ? [] : $this->normalize($snapshot->payload);
    }

    public function forget(): void
    {
        Cache::forget($this->cacheKey());
    }

    public function snapshot(): ?McpToolSnapshot
    {
        return McpToolSnapshot::query()
            ->where('apps_id', $this->app->getId())
            ->where('companies_id', $this->company->getId())
            ->where('integrations_id', $this->integration->getId())
            ->first();
    }

    public function connection(): McpConnectionService
    {
        return $this->connection ??= new McpConnectionService($this->app, $this->company, $this->integration);
    }

    /**
     * A failed fetch must never overwrite a good snapshot — that is the whole point of the durable
     * tier, so only a successful `tools/list` reaches this.
     *
     * @param list<array<string, mixed>> $descriptors
     */
    public function store(array $descriptors): void
    {
        $hash = McpToolSnapshot::hashFor($descriptors);
        $now = Carbon::now();

        $existing = $this->snapshot();

        if ($existing !== null && $existing->payload_hash === $hash) {
            // Nothing changed: touch only the freshness stamp. Rewriting an identical payload would
            // churn updated_at and, worse, restate the LLM prompt prefix for no reason.
            $existing->fetched_at = $now;
            $existing->saveOrFail();
        } else {
            McpToolSnapshot::query()->updateOrCreate(
                [
                    'apps_id' => $this->app->getId(),
                    'companies_id' => $this->company->getId(),
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

        RefreshMcpToolSnapshotJob::dispatch($this->app, $this->company, $this->integration, $this->toolVersion);
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

    /**
     * The tool row's version is part of the key so bumping it is a force-refresh lever for every
     * company at once.
     *
     * Static and public because the invalidation observer has to build the same key from an
     * `integration_companies` row it holds no service for — a second hand-rolled `sprintf` there would
     * drift the day this format changes and silently stop invalidating anything.
     */
    public static function cacheKeyFor(
        int $appsId,
        int $companiesId,
        int $integrationsId,
        string $toolVersion
    ): string {
        return sprintf('mcp:tools:%d:%d:%d:%s', $appsId, $companiesId, $integrationsId, $toolVersion);
    }

    private function cacheKey(): string
    {
        return self::cacheKeyFor(
            $this->app->getId(),
            $this->company->getId(),
            $this->integration->getId(),
            $this->toolVersion
        );
    }

    private function breakerKey(): string
    {
        return sprintf(
            'mcp:breaker:%d:%d:%d',
            $this->app->getId(),
            $this->company->getId(),
            $this->integration->getId()
        );
    }

    private function lockKey(): string
    {
        return $this->cacheKey() . ':lock';
    }
}
