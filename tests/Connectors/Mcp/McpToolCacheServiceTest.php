<?php

declare(strict_types=1);

namespace Tests\Connectors\Mcp;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Kanvas\Connectors\Mcp\Enums\McpConnectionStatusEnum;
use Kanvas\Connectors\Mcp\Exceptions\McpAuthException;
use Kanvas\Connectors\Mcp\Exceptions\McpFetchException;
use Kanvas\Connectors\Mcp\Jobs\RefreshMcpToolSnapshotJob;
use Kanvas\Connectors\Mcp\Services\McpConnectionService;
use Kanvas\Connectors\Mcp\Services\McpToolCacheService;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\NervousSystem\Capability\Models\McpToolSnapshot;
use Kanvas\Workflow\Models\Integrations;
use Tests\Stubs\Connectors\Mcp\FakeMcpServer;
use Throwable;

final class McpToolCacheServiceTest extends McpTestCase
{
    public function testColdReadFetchesOnceAndWritesBothTiers(): void
    {
        $agent = $this->makeAgent();
        $integration = $this->makeIntegration();
        $cache = $this->cacheFor($agent, $integration, FakeMcpServer::listing(FakeMcpServer::twoTools()));

        $this->assertCount(2, $cache->descriptors());
        $this->assertNotNull($cache->snapshot(), 'A successful fetch must land in the durable tier.');
        $this->assertIsArray(Cache::get($this->keyFor($agent, $integration)), 'and in Redis.');
    }

    public function testWarmRedisServesWithoutTouchingTheNetwork(): void
    {
        $agent = $this->makeAgent();
        $integration = $this->makeIntegration();
        $this->cacheFor($agent, $integration, FakeMcpServer::listing(FakeMcpServer::twoTools()))->descriptors();

        // No transport at all: any network attempt here would fail rather than quietly re-fetch.
        $descriptors = new McpToolCacheService(agent: $agent, integration: $integration)->descriptors();

        $this->assertCount(2, $descriptors);
    }

    public function testColdRedisFallsBackToTheSnapshotInsteadOfTheVendor(): void
    {
        $agent = $this->makeAgent();
        $integration = $this->makeIntegration();
        $this->cacheFor($agent, $integration, FakeMcpServer::listing(FakeMcpServer::twoTools()))->descriptors();

        // Simulates a flush, an eviction or a cold deploy — the case that would otherwise stampede
        // every agent at the vendor simultaneously.
        Cache::forget($this->keyFor($agent, $integration));

        $descriptors = new McpToolCacheService(agent: $agent, integration: $integration)->descriptors();

        $this->assertCount(2, $descriptors);
    }

    public function testAFailedFetchNeverOverwritesAGoodSnapshot(): void
    {
        $agent = $this->makeAgent();
        $integration = $this->makeIntegration();
        $this->cacheFor($agent, $integration, FakeMcpServer::listing(FakeMcpServer::twoTools()))->descriptors();

        $before = $this->snapshotOf($agent, $integration);

        try {
            $this->cacheFor($agent, $integration, failWith: new McpFetchException('vendor is down'))->refresh();
        } catch (Throwable) {
            // expected
        }

        $after = $this->snapshotOf($agent, $integration);

        $this->assertSame($before->payload_hash, $after->payload_hash);
        $this->assertSame(2, $after->tool_count, 'The last known good list must survive an outage.');
    }

    public function testFetchFailureStillServesTheStaleSnapshot(): void
    {
        $agent = $this->makeAgent();
        $integration = $this->makeIntegration();
        $this->cacheFor($agent, $integration, FakeMcpServer::listing(FakeMcpServer::twoTools()))->descriptors();
        Cache::forget($this->keyFor($agent, $integration));

        $failing = $this->cacheFor($agent, $integration, failWith: new McpFetchException('timeout'));

        // The tools almost certainly still exist; an invoke that fails cleanly beats an agent that
        // silently lost the capability for the duration of someone else's outage.
        $this->assertCount(2, $failing->descriptors());
    }

    public function testARejectedCredentialFailsOnlyThatAgentsConnection(): void
    {
        $integration = $this->makeIntegration();
        $tool = $this->makeMcpTool($integration);
        $rejected = $this->makeAgent();
        $other = $this->makeAgent();
        $this->connectAgent($rejected, $tool);
        $this->connectAgent($other, $tool);

        $failing = $this->cacheFor($rejected, $integration, failWith: new McpAuthException('401'));

        try {
            $failing->refresh();
            $this->fail('A rejected credential must surface to the caller.');
        } catch (McpAuthException) {
            // expected
        }

        $this->assertTrue($failing->breakerIsOpen());
        $this->assertSame(McpConnectionStatusEnum::FAILED->value, $this->connectionState($rejected, $tool)['status']);
        $this->assertFalse(new McpConnectionService($rejected, $integration)->isEnabled());

        // Under the company model one revoked token switched the server off for every agent.
        $this->assertTrue(new McpConnectionService($other, $integration)->isEnabled());
        $this->assertFalse(new McpToolCacheService(agent: $other, integration: $integration)->breakerIsOpen());
    }

    public function testEachAgentSeesWhatItsOwnCredentialExposes(): void
    {
        $integration = $this->makeIntegration();
        $tool = $this->makeMcpTool($integration);
        $admin = $this->makeAgent();
        $reader = $this->makeAgent();
        $this->connectAgent($admin, $tool, FakeMcpServer::listing(FakeMcpServer::twoTools()));
        $this->connectAgent($reader, $tool, FakeMcpServer::listing([FakeMcpServer::twoTools()[0]]));

        $this->assertCount(2, new McpToolCacheService(agent: $admin, integration: $integration)->descriptors());
        $this->assertCount(1, new McpToolCacheService(agent: $reader, integration: $integration)->descriptors());
    }

    public function testAnOpenBreakerShortCircuitsBeforeDiallingOut(): void
    {
        $agent = $this->makeAgent();
        $integration = $this->makeIntegration();
        $cache = $this->cacheFor($agent, $integration, failWith: new McpFetchException('down'));
        $cache->openBreaker();

        $this->assertSame([], $cache->descriptors());
    }

    public function testAnUnchangedRefreshKeepsTheSameHashAndOnlyMovesTheFreshnessStamp(): void
    {
        $agent = $this->makeAgent();
        $integration = $this->makeIntegration();
        $this->cacheFor($agent, $integration, FakeMcpServer::listing(FakeMcpServer::twoTools()))->descriptors();

        $first = $this->snapshotOf($agent, $integration);

        Carbon::setTestNow(Carbon::now()->addMinutes(5));
        $this->cacheFor($agent, $integration, FakeMcpServer::listing(FakeMcpServer::twoTools()))->refresh();
        Carbon::setTestNow();

        $second = $this->snapshotOf($agent, $integration);

        // A stable hash is what keeps the LLM prompt prefix byte-identical, so the provider's prompt
        // cache is not thrown away on every revalidation.
        $this->assertSame($first->payload_hash, $second->payload_hash);
        $this->assertTrue($second->fetched_at->gt($first->fetched_at));
    }

    public function testABurstOfStaleReadsQueuesOneRevalidation(): void
    {
        Queue::fake();

        $agent = $this->makeAgent();
        $integration = $this->makeIntegration();
        $this->cacheFor($agent, $integration, FakeMcpServer::listing(FakeMcpServer::twoTools()))->descriptors();

        Carbon::setTestNow(Carbon::now()->addHours(2));

        foreach (range(1, 5) as $ignored) {
            new McpToolCacheService(agent: $agent, integration: $integration)->descriptors();
        }

        Carbon::setTestNow();

        Queue::assertPushed(RefreshMcpToolSnapshotJob::class, 1);
    }

    public function testAnOpenBreakerDoesNotQueueABackgroundRevalidation(): void
    {
        Queue::fake();

        $agent = $this->makeAgent();
        $integration = $this->makeIntegration();
        $cache = $this->cacheFor($agent, $integration, FakeMcpServer::listing(FakeMcpServer::twoTools()));
        $cache->descriptors();

        // The breaker outlives a failure by a minute and an entry goes soft-stale after an hour, so the
        // overlap only exists if the breaker is opened once the entry is already stale — trip it there.
        Carbon::setTestNow(Carbon::now()->addHours(2));
        $cache->openBreaker();
        new McpToolCacheService(agent: $agent, integration: $integration)->descriptors();
        Carbon::setTestNow();

        // Dialling a vendor already known to be failing costs a round trip to learn what the breaker
        // already recorded.
        Queue::assertNotPushed(RefreshMcpToolSnapshotJob::class);
    }

    public function testDescriptorsComeBackSortedSoThePromptPrefixIsStable(): void
    {
        $cache = $this->cacheFor(
            $this->makeAgent(),
            $this->makeIntegration(),
            FakeMcpServer::listing(FakeMcpServer::twoTools())
        );

        $names = array_column($cache->descriptors(), 'name');

        $sorted = $names;
        sort($sorted);
        $this->assertSame($sorted, $names);
    }

    public function testThePlatformDenylistIsAppliedBeforeAnythingIsCached(): void
    {
        $cache = $this->cacheFor(
            $this->makeAgent(),
            $this->makeIntegration(['exclude' => ['createJiraIssue']]),
            FakeMcpServer::listing(FakeMcpServer::twoTools())
        );

        $this->assertSame(['searchJiraIssuesUsingJql'], array_column($cache->descriptors(), 'name'));
    }

    private function cacheFor(
        Agent $agent,
        Integrations $integration,
        mixed $transport = null,
        ?Throwable $failWith = null
    ): McpToolCacheService {
        $connection = $failWith !== null
            ? new class ($agent, $integration, $failWith) extends McpConnectionService {
                public function __construct(
                    Agent $agent,
                    Integrations $integration,
                    private readonly Throwable $failure
                ) {
                    parent::__construct($agent, $integration);
                }

                public function fetchDescriptors(): array
                {
                    throw $this->failure;
                }
            }
        : new McpConnectionService($agent, $integration, $transport);

        return new McpToolCacheService(
            agent: $agent,
            integration: $integration,
            connection: $connection,
        );
    }

    private function snapshotOf(Agent $agent, Integrations $integration): McpToolSnapshot
    {
        $snapshot = McpToolSnapshot::query()
            ->where('agents_id', $agent->getId())
            ->where('integrations_id', $integration->getId())
            ->first();

        $this->assertNotNull($snapshot);

        return $snapshot;
    }

    private function keyFor(Agent $agent, Integrations $integration): string
    {
        return McpToolCacheService::cacheKeyFor(
            $agent->apps_id,
            $agent->companies_id,
            $agent->getId(),
            $integration->getId(),
            '1.0.0'
        );
    }
}
