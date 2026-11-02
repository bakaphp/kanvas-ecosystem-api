<?php

declare(strict_types=1);

namespace Tests\Connectors\Mcp;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Kanvas\Connectors\Mcp\DataTransferObject\McpServerConfig;
use Kanvas\Connectors\Mcp\Enums\McpAuthEnum;
use Kanvas\Exceptions\ValidationException;
use Kanvas\NervousSystem\Capability\Enums\ToolTypeEnum;
use Kanvas\NervousSystem\Capability\Models\Tool;
use Kanvas\Workflow\Models\Integrations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * The two migrations that seed the SalesAssist Console as an MCP server. They run here rather than being read
 * back, because CI builds its database from the setup commands and no other test depends on a seeded MCP row.
 *
 * Serial: the rows are global (`apps_id = 0`), and deleting one takes a lock that parallel processes inserting
 * agents and grants collide with.
 */
#[Group('serial')]
final class SalesAssistConsoleMcpSeedTest extends McpTestCase
{
    private const string INTEGRATION_MIGRATION = 'Workflow/2026_10_16_100000_add_salesassist_console_mcp_integration';

    private const string TOOL_MIGRATION = 'Intelligence/2026_10_16_100001_add_salesassist_console_mcp_tool';

    private const string SLUG = 'salesassist_console_mcp';

    private const string TITLE = 'SalesAssist Console (MCP)';

    private const string SERVER = 'https://8.8.4.4/mcp';

    protected function setUp(): void
    {
        parent::setUp();

        DB::connection('intelligence')->table('nervous_system_tools')->where('name', self::TITLE)->delete();
        DB::connection('workflow')->table('integrations')->where('name', self::SLUG)->delete();
    }

    public function testUpSeedsTheServerAndItsHiddenCatalogRow(): void
    {
        $this->seedConsole();

        $integration = $this->integration();
        $tool = $this->tool();

        $this->assertSame('mcp', $integration->type);
        $this->assertSame($integration->getId(), $tool->integrations_id);
        $this->assertSame(ToolTypeEnum::MCP->value, $tool->tool_type);
        $this->assertSame(['neuron'], $tool->frameworks);
        $this->assertSame(0, (int) $tool->is_active);
    }

    public function testRunningUpTwiceSeedsEachRowOnce(): void
    {
        $this->seedConsole();
        $this->seedConsole();

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
        $this->seedConsole();

        $this->migration(self::TOOL_MIGRATION)->down();
        $this->migration(self::INTEGRATION_MIGRATION)->down();

        $this->assertSame(0, DB::connection('intelligence')->table('nervous_system_tools')->where('name', self::TITLE)->count());
        $this->assertSame(0, DB::connection('workflow')->table('integrations')->where('name', self::SLUG)->count());
    }

    public function testTheServerIsBearerOnlyWithAnAddressPerConnection(): void
    {
        $this->seedConsole();

        $config = McpServerConfig::fromIntegration($this->integration());

        $this->assertTrue($config->urlPerConnection);
        $this->assertNull($config->url);
        $this->assertSame('sa_console', $config->prefix);
        $this->assertSame(30000, $config->timeoutMs);
        $this->assertTrue($config->supports(McpAuthEnum::BEARER));
        $this->assertFalse($config->supports(McpAuthEnum::OAUTH));
        $this->assertFalse($config->supports(McpAuthEnum::NONE));
        $this->assertSame([], $config->asyncJobs);
    }

    public function testTheTitleIsUniqueAcrossEveryApp(): void
    {
        $this->seedConsole();

        $this->assertSame(1, DB::connection('intelligence')->table('nervous_system_tools')->where('name', self::TITLE)->count());
    }

    public function testAnAgentConnectsWithTheKeyAndTheConsoleAddress(): void
    {
        $this->seedConsole();
        $agent = $this->makeAgent();

        $this->assertSame(2, $this->connectAgent($agent, $this->tool(), serverUrl: self::SERVER));

        $credentials = $this->credentials($agent, $this->integration());
        $this->assertSame('agent-token', $credentials->rawToken());
        $this->assertSame(self::SERVER, $credentials->serverUrl());
    }

    #[DataProvider('refusedAddresses')]
    public function testAMissingPlainOrPrivateAddressIsRefusedBeforeAnythingIsGranted(?string $address): void
    {
        $this->seedConsole();
        $agent = $this->makeAgent();
        $tool = $this->tool();

        try {
            $this->connectAgent($agent, $tool, serverUrl: $address);
            $this->fail('That address must be refused.');
        } catch (ValidationException) {
            // expected
        }

        $this->assertNull($this->grantFor($agent, $tool));
    }

    /**
     * @return array<string, array{0: string|null}>
     */
    public static function refusedAddresses(): array
    {
        return [
            'missing' => [null],
            'plain http' => ['http://8.8.4.4/mcp'],
            'private address' => ['https://10.0.0.5/mcp'],
        ];
    }

    private function seedConsole(): void
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
