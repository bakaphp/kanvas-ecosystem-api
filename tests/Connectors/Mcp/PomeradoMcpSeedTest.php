<?php

declare(strict_types=1);

namespace Tests\Connectors\Mcp;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Kanvas\Connectors\Mcp\DataTransferObject\McpServerConfig;
use Kanvas\Connectors\Mcp\Enums\McpAuthEnum;
use Kanvas\NervousSystem\Capability\Enums\ToolTypeEnum;
use Kanvas\NervousSystem\Capability\Models\Tool;
use Kanvas\Workflow\Models\Integrations;
use PHPUnit\Framework\Attributes\Group;

/**
 * The two migrations that seed Pomerado as an MCP server: the first row whose every tool starts a job,
 * followed through a status tool that names the job id differently from the result that carries it.
 *
 * Serial: the rows are global (`apps_id = 0`), and deleting one takes a lock that parallel processes inserting
 * agents and grants collide with.
 */
#[Group('serial')]
final class PomeradoMcpSeedTest extends McpTestCase
{
    private const string INTEGRATION_MIGRATION = 'Workflow/2026_11_25_100000_add_pomerado_mcp_integration';

    private const string TOOL_MIGRATION = 'Intelligence/2026_11_25_100001_add_pomerado_mcp_tool';

    private const string SLUG = 'pomerado_mcp';

    private const string TITLE = 'Pomerado';

    protected function setUp(): void
    {
        parent::setUp();

        DB::connection('intelligence')->table('nervous_system_tools')->where('name', self::TITLE)->delete();
        DB::connection('workflow')->table('integrations')->where('name', self::SLUG)->delete();
    }

    public function testUpSeedsTheServerAndItsCatalogRow(): void
    {
        $this->seedServer();

        $integration = $this->integration();
        $tool = $this->tool();

        $this->assertSame('mcp', $integration->type);
        $this->assertSame($integration->getId(), $tool->integrations_id);
        $this->assertSame(ToolTypeEnum::MCP->value, $tool->tool_type);
        $this->assertSame(['neuron'], $tool->frameworks);
        $this->assertSame(1, (int) $tool->is_active);
    }

    public function testTheCatalogRowIsFiledUnderAGlobalCategory(): void
    {
        $this->seedServer();

        $categoryId = DB::connection('intelligence')->table('nervous_system_tool_categories')
            ->where('apps_id', 0)
            ->where('slug', 'code')
            ->value('id');

        $this->assertNotNull($categoryId);
        $this->assertSame((int) $categoryId, (int) $this->tool()->tool_category_id);
    }

    public function testRunningUpTwiceSeedsEachRowOnce(): void
    {
        $this->seedServer();
        $this->seedServer();

        $this->assertSame(1, DB::connection('workflow')->table('integrations')->where('name', self::SLUG)->count());
        $this->assertSame(1, DB::connection('intelligence')->table('nervous_system_tools')->where('name', self::TITLE)->count());
    }

    public function testTheCatalogRowIsNotSeededWithoutTheServerItPointsAt(): void
    {
        $this->migration(self::TOOL_MIGRATION)->up();

        $this->assertSame(0, DB::connection('intelligence')->table('nervous_system_tools')->where('name', self::TITLE)->count());
    }

    public function testDownRemovesBothRows(): void
    {
        $this->seedServer();

        $this->migration(self::TOOL_MIGRATION)->down();
        $this->migration(self::INTEGRATION_MIGRATION)->down();

        $this->assertSame(0, DB::connection('intelligence')->table('nervous_system_tools')->where('name', self::TITLE)->count());
        $this->assertSame(0, DB::connection('workflow')->table('integrations')->where('name', self::SLUG)->count());
    }

    public function testTheServerOffersOAuthAndAPastedKeyAsAPlainBearer(): void
    {
        $this->seedServer();

        $config = McpServerConfig::fromIntegration($this->integration());

        $this->assertSame('https://mcp.pomerado.ai/mcp', $config->url);
        $this->assertFalse($config->urlPerConnection);
        $this->assertSame('pomerado', $config->prefix);
        $this->assertNull($config->authHeader);
        $this->assertNull($config->authQueryParam);
        $this->assertTrue($config->supports(McpAuthEnum::OAUTH));
        $this->assertTrue($config->supports(McpAuthEnum::BEARER));
        $this->assertFalse($config->supports(McpAuthEnum::NONE));
        $this->assertSame(
            [
                'openid',
                'offline_access',
            ],
            $config->oauth['scopes']
        );
    }

    public function testACallMayWaitAsLongAsTheServerAsksTheModelTo(): void
    {
        $this->seedServer();

        $this->assertGreaterThan(240000, McpServerConfig::fromIntegration($this->integration())->timeoutMs);
    }

    public function testEveryToolThatStartsAJobIsFollowedThroughGetJob(): void
    {
        $this->seedServer();

        $config = McpServerConfig::fromIntegration($this->integration());

        // MySQL stores a JSON object's keys in its own order, so the set is compared, not the sequence.
        $this->assertEqualsCanonicalizing(
            [
                'run_read_only_website_tool',
                'run_website_tool',
                'build_website_tool',
                'change_website_tool',
            ],
            array_keys($config->asyncJobs)
        );

        foreach ($config->asyncJobs as $job) {
            $this->assertSame('get_job', $job->statusTool);
            $this->assertSame(['job_id' => 'job_1'], $job->statusArguments('job_1'));
            $this->assertSame('job_1', $job->jobIdFrom(['id' => 'job_1', 'status' => 'running']));
            $this->assertFalse($job->isDone('queued'));
            $this->assertFalse($job->isDone('running'));
            $this->assertTrue($job->isDone('succeeded'));
            $this->assertTrue($job->isDone('failed'));
            $this->assertTrue($job->isDone('cancelled'));
            $this->assertNull($job->liveUrlField);
        }
    }

    public function testAJobWaitingOnAPersonWakesTheAgentInsteadOfBeingPolled(): void
    {
        $this->seedServer();

        $build = McpServerConfig::fromIntegration($this->integration())->asyncJob('build_website_tool');

        $this->assertTrue($build?->isDone('needs_input'));
        $this->assertTrue($build?->isDone('paused'));
    }

    public function testTheAccountAdministrationToolsAreNotOffered(): void
    {
        $this->seedServer();

        $config = McpServerConfig::fromIntegration($this->integration());

        foreach ([
            'sign_in',
            'report_issue',
            'search_pomerado_api',
            'describe_pomerado_api',
            'call_read_only_pomerado_api',
            'call_pomerado_api',
        ] as $tool) {
            $this->assertTrue($config->isExcluded($tool), $tool);
        }

        foreach ([
            'find_website_tool',
            'run_read_only_website_tool',
            'run_website_tool',
            'build_website_tool',
            'get_job',
            'answer_job',
            'cancel_job',
        ] as $tool) {
            $this->assertFalse($config->isExcluded($tool), $tool);
        }
    }

    public function testAnAgentConnectsWithAPastedKey(): void
    {
        $this->seedServer();
        $agent = $this->makeAgent();

        $this->assertSame(2, $this->connectAgent($agent, $this->tool(), token: 'pom_test_key'));

        $credentials = $this->credentials($agent, $this->integration());
        $this->assertSame('pom_test_key', $credentials->rawToken());
        $this->assertFalse($credentials->isOAuthGrant());
    }

    private function seedServer(): void
    {
        $this->migration(self::INTEGRATION_MIGRATION)->up();
        $this->migration(self::TOOL_MIGRATION)->up();
    }

    private function migration(string $name): Migration
    {
        return require database_path('migrations/' . $name . '.php');
    }

    private function integration(): Integrations
    {
        return Integrations::query()->where('name', self::SLUG)->where('apps_id', 0)->firstOrFail();
    }

    private function tool(): Tool
    {
        return Tool::query()->where('name', self::TITLE)->where('apps_id', 0)->firstOrFail();
    }
}
