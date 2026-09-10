<?php

declare(strict_types=1);

namespace Tests\Connectors\Mcp;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Kanvas\Connectors\Mcp\Exceptions\McpAuthException;
use Kanvas\Connectors\Mcp\Exceptions\McpFetchException;
use Kanvas\Connectors\Mcp\Services\McpConnectionService;
use Kanvas\Connectors\Mcp\Services\McpToolCacheService;
use Kanvas\NervousSystem\Capability\Models\McpToolSnapshot;
use Kanvas\Workflow\Enums\StatusEnum;
use Kanvas\Workflow\Models\Integrations;
use Tests\Stubs\Connectors\Mcp\FakeMcpServer;
use Throwable;

final class McpToolCacheServiceTest extends McpTestCase
{
    public function testColdReadFetchesOnceAndWritesBothTiers(): void
    {
        $integration = $this->makeIntegration();
        $cache = $this->cacheFor($integration, FakeMcpServer::listing(FakeMcpServer::twoTools()));

        $descriptors = $cache->descriptors();

        $this->assertCount(2, $descriptors);
        $this->assertNotNull($cache->snapshot(), 'A successful fetch must land in the durable tier.');
        $this->assertIsArray(Cache::get($this->keyFor($integration)), 'and in Redis.');
    }

    public function testWarmRedisServesWithoutTouchingTheNetwork(): void
    {
        $integration = $this->makeIntegration();
        $this->cacheFor($integration, FakeMcpServer::listing(FakeMcpServer::twoTools()))->descriptors();

        // No transport at all: any network attempt here would fatal rather than quietly re-fetch.
        $descriptors = new McpToolCacheService(
            app: $this->mcpApp,
            company: $this->mcpCompany,
            integration: $integration,
        )->descriptors();

        $this->assertCount(2, $descriptors);
    }

    public function testColdRedisFallsBackToTheSnapshotInsteadOfTheVendor(): void
    {
        $integration = $this->makeIntegration();
        $cache = $this->cacheFor($integration, FakeMcpServer::listing(FakeMcpServer::twoTools()));
        $cache->descriptors();

        // Simulates a flush, an eviction or a cold deploy — the case that would otherwise stampede
        // every company at the vendor simultaneously.
        Cache::forget($this->keyFor($integration));

        $descriptors = new McpToolCacheService(
            app: $this->mcpApp,
            company: $this->mcpCompany,
            integration: $integration,
        )->descriptors();

        $this->assertCount(2, $descriptors);
    }

    public function testAFailedFetchNeverOverwritesAGoodSnapshot(): void
    {
        $integration = $this->makeIntegration();
        $this->cacheFor($integration, FakeMcpServer::listing(FakeMcpServer::twoTools()))->descriptors();

        $before = $cacheSnapshot = McpToolSnapshot::query()
            ->where('integrations_id', $integration->getId())
            ->firstOrFail();

        $failing = $this->cacheFor($integration, null, new McpFetchException('vendor is down'));

        try {
            $failing->refresh();
        } catch (Throwable) {
            // expected
        }

        $after = McpToolSnapshot::query()->where('integrations_id', $integration->getId())->firstOrFail();

        $this->assertSame($before->payload_hash, $after->payload_hash);
        $this->assertSame(2, $after->tool_count, 'The last known good list must survive an outage.');
        $this->assertNotNull($cacheSnapshot);
    }

    public function testFetchFailureStillServesTheStaleSnapshot(): void
    {
        $integration = $this->makeIntegration();
        $this->cacheFor($integration, FakeMcpServer::listing(FakeMcpServer::twoTools()))->descriptors();
        Cache::forget($this->keyFor($integration));

        $failing = $this->cacheFor($integration, null, new McpFetchException('timeout'));

        // The tools almost certainly still exist; an invoke that fails cleanly beats an agent that
        // silently lost the capability for the duration of someone else's outage.
        $this->assertCount(2, $failing->descriptors());
    }

    public function testAuthFailureFlipsTheIntegrationToFailedAndServesNothing(): void
    {
        $integration = $this->makeIntegration();
        $row = $this->enableForCompany($integration);

        $failing = $this->cacheFor($integration, null, new McpAuthException('401'));

        $this->assertSame([], $failing->descriptors());
        $this->assertTrue($failing->breakerIsOpen());

        $row->refresh();
        $this->assertSame(StatusEnum::FAILED->value, $row->status->slug);
    }

    public function testAnOpenBreakerShortCircuitsBeforeDiallingOut(): void
    {
        $integration = $this->makeIntegration();
        $cache = $this->cacheFor($integration, null, new McpFetchException('down'));
        $cache->openBreaker();

        // No transport is configured, so reaching the network here would throw rather than return [].
        $this->assertSame([], $cache->descriptors());
    }

    public function testAnUnchangedRefreshKeepsTheSameHashAndOnlyMovesTheFreshnessStamp(): void
    {
        $integration = $this->makeIntegration();
        $this->cacheFor($integration, FakeMcpServer::listing(FakeMcpServer::twoTools()))->descriptors();

        $first = McpToolSnapshot::query()->where('integrations_id', $integration->getId())->firstOrFail();
        $originalHash = $first->payload_hash;

        Carbon::setTestNow(Carbon::now()->addMinutes(5));
        $this->cacheFor($integration, FakeMcpServer::listing(FakeMcpServer::twoTools()))->refresh();
        Carbon::setTestNow();

        $second = McpToolSnapshot::query()->where('integrations_id', $integration->getId())->firstOrFail();

        // A stable hash is what keeps the LLM prompt prefix byte-identical, so the provider's prompt
        // cache is not thrown away on every revalidation.
        $this->assertSame($originalHash, $second->payload_hash);
        $this->assertTrue($second->fetched_at->gt($first->fetched_at));
    }

    public function testDescriptorsComeBackSortedSoThePromptPrefixIsStable(): void
    {
        $integration = $this->makeIntegration();
        $cache = $this->cacheFor($integration, FakeMcpServer::listing(FakeMcpServer::twoTools()));

        $names = array_column($cache->descriptors(), 'name');

        $sorted = $names;
        sort($sorted);
        $this->assertSame($sorted, $names);
    }

    public function testThePlatformDenylistIsAppliedBeforeAnythingIsCached(): void
    {
        $integration = $this->makeIntegration(['exclude' => ['createJiraIssue']]);
        $cache = $this->cacheFor($integration, FakeMcpServer::listing(FakeMcpServer::twoTools()));

        $names = array_column($cache->descriptors(), 'name');

        $this->assertSame(['searchJiraIssuesUsingJql'], $names);
    }

    private function cacheFor(
        Integrations $integration,
        mixed $transport = null,
        ?Throwable $failWith = null
    ): McpToolCacheService {
        $connection = $failWith !== null
            ? new class ($this->mcpApp, $this->mcpCompany, $integration, $failWith) extends McpConnectionService {
                public function __construct(
                    $app,
                    $company,
                    $integration,
                    private readonly Throwable $failure
                ) {
                    parent::__construct($app, $company, $integration);
                }

                public function fetchDescriptors(): array
                {
                    throw $this->failure;
                }
            }
        : new McpConnectionService($this->mcpApp, $this->mcpCompany, $integration, $transport);

        return new McpToolCacheService(
            app: $this->mcpApp,
            company: $this->mcpCompany,
            integration: $integration,
            connection: $connection,
        );
    }

    private function keyFor(Integrations $integration): string
    {
        return McpToolCacheService::cacheKeyFor(
            $this->mcpApp->getId(),
            $this->mcpCompany->getId(),
            $integration->getId(),
            '1.0.0'
        );
    }
}
