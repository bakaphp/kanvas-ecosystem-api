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
 * The two migrations that seed Google Keyword Planner (an Apify Actor behind Apify's hosted MCP server). The URL's
 * `?tools=` is what keeps Apify's default catalogue (Actor search, docs, the RAG browser) out of the agent's
 * tool list, so it is pinned here.
 *
 * Serial: the rows are global (`apps_id = 0`), and deleting one takes a lock that parallel processes inserting
 * agents and grants collide with.
 */
#[Group('serial')]
final class GoogleKeywordPlannerMcpSeedTest extends McpTestCase
{
    private const string INTEGRATION_MIGRATION = 'Workflow/2026_10_24_110000_add_google_keyword_planner_mcp_integration';

    private const string TOOL_MIGRATION = 'Intelligence/2026_10_24_110001_add_google_keyword_planner_mcp_tool';

    private const string SLUG = 'google_keyword_planner_mcp';

    private const string TITLE = 'Google Keyword Planner';

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

    public function testTheRowPinsTheActorAndOffersOAuthOrAnApifyToken(): void
    {
        $this->seedServer();

        $config = McpServerConfig::fromIntegration($this->integration());

        $this->assertSame('https://mcp.apify.com/?tools=smacient/google-keyword-planner-mcp', $config->url);
        $this->assertFalse($config->urlPerConnection);
        $this->assertSame('gkp', $config->prefix);
        $this->assertNull($config->authHeader);
        $this->assertNull($config->authQueryParam);
        $this->assertSame(60000, $config->timeoutMs);
        $this->assertTrue($config->supports(McpAuthEnum::OAUTH));
        $this->assertTrue($config->supports(McpAuthEnum::BEARER));
        $this->assertFalse($config->supports(McpAuthEnum::NONE));
        $this->assertSame(['full_api_access'], $config->oauth['scopes']);
        $this->assertSame([], $config->exclude);
        $this->assertSame([], $config->asyncJobs);
    }

    public function testAnAgentConnectsWithAPastedToken(): void
    {
        $this->seedServer();
        $agent = $this->makeAgent();

        $this->assertSame(2, $this->connectAgent($agent, $this->tool(), token: 'apify_api_token'));

        $credentials = $this->credentials($agent, $this->integration());
        $this->assertSame('apify_api_token', $credentials->rawToken());
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
