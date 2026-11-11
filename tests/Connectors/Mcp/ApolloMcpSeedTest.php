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
 * The two migrations that seed Apollo.io as an MCP server — the first row to offer OAuth beside a key that
 * travels in its own header, which is what makes the transport's method split matter.
 *
 * Serial: the rows are global (`apps_id = 0`), and deleting one takes a lock that parallel processes inserting
 * agents and grants collide with.
 */
#[Group('serial')]
final class ApolloMcpSeedTest extends McpTestCase
{
    private const string INTEGRATION_MIGRATION = 'Workflow/2026_10_20_120000_add_apollo_mcp_integration';

    private const string TOOL_MIGRATION = 'Intelligence/2026_10_20_120001_add_apollo_mcp_tool';

    private const string SLUG = 'apollo_mcp';

    private const string TITLE = 'Apollo (MCP)';

    protected function setUp(): void
    {
        parent::setUp();

        DB::connection('intelligence')->table('nervous_system_tools')->where('name', self::TITLE)->delete();
        DB::connection('workflow')->table('integrations')->where('name', self::SLUG)->delete();
    }

    public function testUpSeedsTheServerAndItsCatalogRow(): void
    {
        $this->seedApollo();

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
        $this->seedApollo();
        $this->seedApollo();

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
        $this->seedApollo();

        $this->migration(self::TOOL_MIGRATION)->down();
        $this->migration(self::INTEGRATION_MIGRATION)->down();

        $this->assertSame(0, DB::connection('intelligence')->table('nervous_system_tools')->where('name', self::TITLE)->count());
        $this->assertSame(0, DB::connection('workflow')->table('integrations')->where('name', self::SLUG)->count());
    }

    public function testTheServerOffersOAuthAndAKeyInItsOwnHeader(): void
    {
        $this->seedApollo();

        $config = McpServerConfig::fromIntegration($this->integration());

        $this->assertSame('https://mcp.apollo.io/mcp', $config->url);
        $this->assertFalse($config->urlPerConnection);
        $this->assertSame('apollo', $config->prefix);
        $this->assertSame('X-Api-Key', $config->authHeader);
        $this->assertNull($config->authQueryParam);
        $this->assertSame(30000, $config->timeoutMs);
        $this->assertTrue($config->supports(McpAuthEnum::OAUTH));
        $this->assertTrue($config->supports(McpAuthEnum::BEARER));
        $this->assertFalse($config->supports(McpAuthEnum::NONE));
        $this->assertSame([], $config->asyncJobs);
    }

    public function testNoScopeThatSpendsMoneyOrSendsMailIsRequested(): void
    {
        $this->seedApollo();

        $scopes = McpServerConfig::fromIntegration($this->integration())->oauth['scopes'];

        $this->assertContains('people_match', $scopes);
        $this->assertContains('emailer_campaigns_add_contact_ids', $scopes);

        foreach ([
            'email_account_purchase_create',
            'domain_purchase_create',
            'emailer_messages_send_now',
            'emailer_messages_create',
            'emailer_campaigns_create',
            'emailer_campaigns_approve',
            'waterfall_enrichments',
            'agent_task',
            'ema_issuer:manage',
        ] as $scope) {
            $this->assertNotContains($scope, $scopes);
        }
    }

    public function testTheToolsBehindUnrequestedScopesAreDeniedToAKeyAsWell(): void
    {
        $this->seedApollo();

        $config = McpServerConfig::fromIntegration($this->integration());

        $this->assertTrue($config->isExcluded('apollo_emailer_messages_send_now'));
        $this->assertTrue($config->isExcluded('apollo_domain_purchase_create'));
        $this->assertTrue($config->isExcluded('apollo_waterfall_enrichments'));
        $this->assertFalse($config->isExcluded('apollo_people_match'));
        $this->assertFalse($config->isExcluded('apollo_emailer_campaigns_add_contact_ids'));
    }

    public function testAnAgentConnectsWithAPastedKey(): void
    {
        $this->seedApollo();
        $agent = $this->makeAgent();

        $this->assertSame(2, $this->connectAgent($agent, $this->tool(), token: 'apollo-key'));

        $credentials = $this->credentials($agent, $this->integration());
        $this->assertSame('apollo-key', $credentials->rawToken());
        $this->assertFalse($credentials->isOAuthGrant());
    }

    private function seedApollo(): void
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
