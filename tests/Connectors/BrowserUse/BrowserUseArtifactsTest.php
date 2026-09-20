<?php

declare(strict_types=1);

namespace Tests\Connectors\BrowserUse;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Kanvas\Connectors\BrowserUse\Client;
use Kanvas\Connectors\BrowserUse\Enums\ConfigurationEnum;
use Kanvas\Connectors\BrowserUse\Services\BrowserUseArtifactCollector;
use Kanvas\Connectors\Mcp\Actions\AttachMcpJobArtifactsToPlanAction;
use Kanvas\Connectors\Mcp\Actions\FollowMcpAsyncJobAction;
use Kanvas\Connectors\Mcp\DataTransferObject\McpServerConfig;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\NervousSystem\Capability\Enums\McpAsyncJobStatusEnum;
use Kanvas\NervousSystem\Capability\Models\McpAsyncJob;
use Kanvas\NervousSystem\Plan\Models\Plan;
use Kanvas\Workflow\Models\Integrations;
use Tests\Connectors\Mcp\McpTestCase;
use Tests\Stubs\Connectors\Mcp\FakeMcpServer;

/**
 * A Browser Use session writes its files to a sandbox that is destroyed when the session stops — the run
 * that "saved the report to outbound_orders.csv" left nothing behind. Every run now carries the company's
 * workspace, and what a finished job produced is pulled into Kanvas and attached to a plan.
 */
final class BrowserUseArtifactsTest extends McpTestCase
{
    private const string WORKSPACE_ID = '6f1c2b3a-0000-4000-8000-000000000001';

    /** Their real shape. A resolvable host on purpose: the SSRF guard resolves DNS before any fetch. */
    private const string PRESIGNED_URL = 'https://browser-use-production-private.s3.us-east-2.amazonaws.com/bu/'
        . 'workspaces/6b7f6f5d/outbound_orders_2026-09-15.csv?X-Amz-Algorithm=AWS4-HMAC-SHA256&X-Amz-Signature=abc';

    private const string SECOND_URL = 'https://browser-use-production-private.s3.us-east-2.amazonaws.com/bu/'
        . 'workspaces/6b7f6f5d/output.json?X-Amz-Signature=def';

    public function testANewSessionCarriesTheCompanyWorkspaceSoItsFilesOutliveTheSandbox(): void
    {
        Http::fake([Client::BASE_URL . '/workspaces' => Http::response(['id' => self::WORKSPACE_ID])]);
        [$agent, $integration] = $this->browserUseServer();

        $defaults = new BrowserUseArtifactCollector()->defaultArguments($agent, $integration, 'run_session');

        $this->assertSame(['workspace_id' => self::WORKSPACE_ID], $defaults);
        $this->assertSame(
            self::WORKSPACE_ID,
            $agent->company->get(ConfigurationEnum::WORKSPACE_ID->value),
            'The workspace is created once and remembered on the company.'
        );
    }

    public function testTheWorkspaceIsCreatedOnceAndReusedAfterwards(): void
    {
        Http::fake([Client::BASE_URL . '/workspaces' => Http::response(['id' => self::WORKSPACE_ID])]);
        [$agent, $integration] = $this->browserUseServer();
        $collector = new BrowserUseArtifactCollector();

        $collector->defaultArguments($agent, $integration, 'run_session');
        $collector->defaultArguments($agent, $integration, 'run_session');

        Http::assertSentCount(1);
    }

    public function testAFollowUpTaskNeedsNoWorkspaceBecauseItReusesTheSession(): void
    {
        Http::fake();
        [$agent, $integration] = $this->browserUseServer();

        $this->assertSame([], new BrowserUseArtifactCollector()->defaultArguments($agent, $integration, 'send_task'));
        Http::assertNothingSent();
    }

    public function testFilesTheJobWroteAndFilesTheBrowserDownloadedAreBothCollected(): void
    {
        [$agent, $integration] = $this->browserUseServer();
        $agent->company->set(ConfigurationEnum::WORKSPACE_ID->value, self::WORKSPACE_ID);
        $job = $this->makeAsyncJob($agent, $integration);

        Http::fake([
            Client::BASE_URL . '/workspaces/*/files*' => Http::response([
                'files' => [
                    ['path' => 'reports/outbound_orders_2026-09-14.csv', 'url' => 'https://s3.example.test/csv?sig=1'],
                    ['path' => 'output.json', 'size' => 12],
                ],
            ]),
            Client::BASE_URL . '/browsers?*' => Http::response(['items' => [['id' => 'browser-1']]]),
            Client::BASE_URL . '/browsers/browser-1/downloads*' => Http::response([
                'files' => [['path' => 'OutboundInquiry.xlsx', 'url' => 'https://s3.example.test/xlsx?sig=2']],
            ]),
        ]);

        $artifacts = new BrowserUseArtifactCollector()->collect($job);

        // The export the browser downloaded matters as much as what the agent wrote — and a file with no
        // download url is unusable, so it is dropped rather than attached as a broken link.
        $this->assertSame(
            ['outbound_orders_2026-09-14.csv', 'OutboundInquiry.xlsx'],
            array_column($artifacts, 'name')
        );
    }

    public function testOnlyWhatThisRunWroteIsCollectedFromTheSharedWorkspace(): void
    {
        [$agent, $integration] = $this->browserUseServer();
        $agent->company->set(ConfigurationEnum::WORKSPACE_ID->value, self::WORKSPACE_ID);
        $job = $this->makeAsyncJob($agent, $integration);

        Http::fake([
            Client::BASE_URL . '/workspaces/*/files*' => Http::response([
                'files' => [
                    [
                        'path' => 'outbound_orders_2026-09-14.csv',
                        'url' => self::PRESIGNED_URL,
                        'lastModified' => Carbon::now()->subWeek()->toIso8601String(),
                    ],
                    [
                        'path' => 'outbound_orders_2026-09-15.csv',
                        'url' => self::SECOND_URL,
                        'lastModified' => Carbon::now()->toIso8601String(),
                    ],
                ],
            ]),
            Client::BASE_URL . '/browsers?*' => Http::response(['items' => []]),
        ]);

        $artifacts = new BrowserUseArtifactCollector()->collect($job);

        // The workspace is per company and permanent — without a bound, every run would re-attach every
        // file the company ever produced, and yesterday's report would ride along with today's.
        $this->assertSame(['outbound_orders_2026-09-15.csv'], array_column($artifacts, 'name'));
    }

    public function testTheServerRowIsWhatPointsAJobAtItsVendorCollector(): void
    {
        [$agent, $integration] = $this->browserUseServer();
        $agent->company->set(ConfigurationEnum::WORKSPACE_ID->value, self::WORKSPACE_ID);
        $job = $this->makeAsyncJob($agent, $integration);

        Http::fake([
            Client::BASE_URL . '/workspaces/*/files*' => Http::response([
                'files' => [['path' => 'output.json', 'url' => self::SECOND_URL]],
            ]),
            Client::BASE_URL . '/browsers?*' => Http::response(['items' => []]),
        ]);

        // The generic job layer knows no vendor: it reaches the collector through the row's metadata.
        $this->assertSame(['output.json'], array_column(AttachMcpJobArtifactsToPlanAction::collectFor($job), 'name'));
        $this->assertSame([], AttachMcpJobArtifactsToPlanAction::collectFor($this->makeAsyncJob($agent, $this->makeIntegration())));
    }

    public function testAJobWithNoWorkspaceStillCollectsTheBrowserDownloads(): void
    {
        [$agent, $integration] = $this->browserUseServer();
        $job = $this->makeAsyncJob($agent, $integration);

        Http::fake([
            Client::BASE_URL . '/browsers?*' => Http::response(['items' => [['id' => 'browser-1']]]),
            Client::BASE_URL . '/browsers/browser-1/downloads*' => Http::response([
                'files' => [['path' => 'export.csv', 'url' => 'https://s3.example.test/export?sig=3']],
            ]),
        ]);

        $this->assertSame(['export.csv'], array_column(new BrowserUseArtifactCollector()->collect($job), 'name'));
    }

    public function testTheServerRowResolvesItsCollector(): void
    {
        [, $integration] = $this->browserUseServer();

        $this->assertInstanceOf(
            BrowserUseArtifactCollector::class,
            McpServerConfig::fromIntegration($integration)->artifactCollector()
        );
    }

    public function testARowNamingAClassThatIsNotACollectorGetsNone(): void
    {
        $integration = $this->makeIntegration(['artifacts_handler' => self::class]);

        $this->assertNull(McpServerConfig::fromIntegration($integration)->artifactCollector());
        $this->assertNull(McpServerConfig::fromIntegration($this->makeIntegration())->artifactCollector());
    }

    public function testTheResumeInstructionPointsAtThePlanInsteadOfCarryingTheFile(): void
    {
        [$agent, $integration] = $this->browserUseServer();
        $job = $this->makeAsyncJob($agent, $integration);
        $job->artifacts = ['plan_id' => 77, 'files' => ['outbound_orders_2026-09-14.csv']];
        $job->finish(McpAsyncJobStatusEnum::COMPLETED, result: '{"output": "done"}');

        $instruction = $job->resumeInstruction();

        // A day's export would bury the turn, so the agent gets a pointer it can hand on, not the contents.
        $this->assertStringContainsString('plan #77', $instruction);
        $this->assertStringContainsString('outbound_orders_2026-09-14.csv', $instruction);
        $this->assertStringContainsString('do not ask', $instruction);
    }

    public function testNothingIsAttachedWhenTheJobProducedNoFiles(): void
    {
        [$agent, $integration] = $this->browserUseServer();

        $this->assertNull(new AttachMcpJobArtifactsToPlanAction($this->makeAsyncJob($agent, $integration), [])->execute());

        // Nor when every file is unreachable: a plan with no files is noise on the board.
        Http::fake([self::PRESIGNED_URL => Http::response('', 403)]);

        $this->assertNull(new AttachMcpJobArtifactsToPlanAction($this->makeAsyncJob($agent, $integration), [
            ['url' => self::PRESIGNED_URL, 'name' => 'expired.csv'],
        ])->execute());
    }

    public function testTheFileIsDownloadedIntoKanvasRatherThanLinkedAtTheVendor(): void
    {
        [$agent, $integration] = $this->browserUseServer();
        $job = $this->makeAsyncJob($agent, $integration);
        Http::fake([self::PRESIGNED_URL => Http::response("order,qty\nDN-1,2\n")]);

        $plan = new AttachMcpJobArtifactsToPlanAction($job, [
            ['url' => self::PRESIGNED_URL, 'name' => 'outbound_orders_2026-09-14.csv'],
        ])->execute();

        $file = $plan?->getFiles()->first();

        // The vendor's link is presigned, dead within the minute and needs its credentials — a plan
        // holding one holds a file nobody can open.
        $this->assertNotNull($file);
        $this->assertStringNotContainsString('X-Amz-Signature', (string) $file->url);
        $this->assertStringNotContainsString('browser-use-production-private', (string) $file->url);
        $this->assertSame('outbound_orders_2026-09-14.csv', $file->name);
    }

    public function testAFileThatCannotBeDownloadedCostsOnlyItself(): void
    {
        [$agent, $integration] = $this->browserUseServer();
        $job = $this->makeAsyncJob($agent, $integration);
        Http::fake([
            self::PRESIGNED_URL => Http::response('', 403),
            self::SECOND_URL => Http::response('{"rows": []}'),
        ]);

        $plan = new AttachMcpJobArtifactsToPlanAction($job, [
            ['url' => self::PRESIGNED_URL, 'name' => 'expired.csv'],
            ['url' => self::SECOND_URL, 'name' => 'output.json'],
        ])->execute();

        $this->assertSame(['output.json'], $plan?->getFiles()->pluck('name')->all());
    }

    public function testTheFilesLandOnAPlanTheAgentCanHandOn(): void
    {
        [$agent, $integration] = $this->browserUseServer();
        $job = $this->makeAsyncJob($agent, $integration);
        Http::fake([self::PRESIGNED_URL => Http::response("order,qty\nDN-1,2\n")]);

        $plan = new AttachMcpJobArtifactsToPlanAction($job, [
            ['url' => self::PRESIGNED_URL, 'name' => 'outbound_orders_2026-09-14.csv'],
        ])->execute();

        $this->assertNotNull($plan);
        $this->assertSame(AttachMcpJobArtifactsToPlanAction::PLAN_TYPE, $plan->plan_type);
        $this->assertSame($agent->getId(), $plan->agent_id);
        $this->assertSame(McpAsyncJob::class, $plan->entity_namespace);
        $this->assertSame($job->getId(), (int) $plan->entity_id);
        $this->assertStringContainsString('outbound_orders_2026-09-14.csv', (string) $plan->description);
    }

    public function testTheAgentIsToldOnlyAboutFilesThatActuallyLanded(): void
    {
        Queue::fake();
        [$agent, $integration] = $this->browserUseServer();
        $agent->company->set(ConfigurationEnum::WORKSPACE_ID->value, self::WORKSPACE_ID);
        $job = $this->makeAsyncJob($agent, $integration);

        Http::fake([
            Client::BASE_URL . '/workspaces/*/files*' => Http::response([
                'files' => [
                    ['path' => 'outbound.csv', 'url' => self::PRESIGNED_URL],
                    ['path' => 'gone.json', 'url' => self::SECOND_URL],
                ],
            ]),
            Client::BASE_URL . '/browsers?*' => Http::response(['items' => []]),
            self::PRESIGNED_URL => Http::response("order,qty\nDN-1,2\n"),
            self::SECOND_URL => Http::response('', 403),
        ]);

        $job = new FollowMcpAsyncJobAction($job, FakeMcpServer::handshakeThenCalls([
            'content' => [['type' => 'text', 'text' => '{"session_id": "session-1", "status": "idle"}']],
        ]))->execute();

        // Announcing a file that failed to download would have the agent promise one nobody can open.
        $this->assertSame(['outbound.csv'], $job->artifacts['files']);
        $this->assertSame(
            ['outbound.csv'],
            Plan::find($job->artifacts['plan_id'])?->getFiles()->pluck('name')->all()
        );
    }

    /**
     * @return array{0: Agent, 1: Integrations}
     */
    private function browserUseServer(): array
    {
        $integration = $this->makeIntegration([
            'artifacts_handler' => BrowserUseArtifactCollector::class,
            'async_jobs' => ['run_session' => [
                'status_tool' => 'get_session',
                'id_field' => 'session_id',
                'done_statuses' => ['idle'],
            ]],
        ]);
        $agent = $this->makeAgent();
        $this->credentials($agent, $integration)->store('bu-test-key');

        return [$agent, $integration];
    }
}
