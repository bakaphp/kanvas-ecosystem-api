<?php

declare(strict_types=1);

namespace Tests\Unit\Connectors;

use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Str;
use Kanvas\Connectors\Mcp\Handlers\McpHandler;
use Kanvas\NervousSystem\Capability\Enums\ToolTypeEnum;
use Kanvas\Workflow\Enums\IntegrationTypeEnum;
use Override;
use PHPUnit\Framework\TestCase;

/**
 * Isolated databases let migration rollback tests exercise global catalog rows without mutating the
 * shared apps_id=0 rows that other suites read.
 */
final class KiasMcpMigrationTest extends TestCase
{
    private Manager $database;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->database = new Manager(new Container());

        foreach (['workflow', 'intelligence'] as $connection) {
            $this->database->addConnection(['driver' => 'sqlite', 'database' => ':memory:'], $connection);
        }

        $this->database->getContainer()->instance('db', $this->database->getDatabaseManager());
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->database->getContainer());

        $this->database->getConnection('workflow')->getSchemaBuilder()->create('integrations', function (Blueprint $table): void {
            $table->id();
            $table->string('uuid');
            $table->string('name');
            $table->string('handler');
            $table->string('type');
            $table->unsignedBigInteger('apps_id');
            $table->text('config');
            $table->text('metadata');
            $table->boolean('is_deleted');
            $table->timestamps();
        });

        $this->database->getConnection('intelligence')->getSchemaBuilder()->create('nervous_system_tools', function (Blueprint $table): void {
            $table->id();
            $table->string('uuid');
            $table->unsignedBigInteger('apps_id');
            $table->string('name');
            $table->text('description');
            $table->string('tool_type');
            $table->string('handler')->nullable();
            $table->unsignedBigInteger('integrations_id');
            $table->text('frameworks');
            $table->string('version');
            $table->boolean('is_active');
            $table->boolean('is_deleted');
            $table->timestamps();
        });
    }

    #[Override]
    protected function tearDown(): void
    {
        foreach (['workflow', 'intelligence'] as $connection) {
            $this->database->getDatabaseManager()->purge($connection);
        }

        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);
        parent::tearDown();
    }

    public function testRegistersBearerOnlyTaskIntegrationAndNeuronCatalog(): void
    {
        $this->integrationMigration()->up();
        $this->toolMigration()->up();

        $integration = DB::connection('workflow')->table('integrations')->sole();
        $tool = DB::connection('intelligence')->table('nervous_system_tools')->sole();
        $metadata = json_decode($integration->metadata, true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('kias_mcp', $integration->name);
        $this->assertSame(McpHandler::class, $integration->handler);
        $this->assertSame(IntegrationTypeEnum::MCP->value, $integration->type);
        $this->assertSame(0, $integration->apps_id);
        $this->assertSame([], json_decode($integration->config, true));
        $this->assertSame('KIAS', $metadata['vendor']);
        $this->assertSame('http', $metadata['transport']);
        $this->assertSame(['bearer'], $metadata['auth_methods']);
        $this->assertSame('kias', $metadata['prefix']);
        $this->assertTrue($metadata['url_per_connection']);
        $this->assertSame(20000, $metadata['timeout_ms']);
        $this->assertArrayNotHasKey('url', $metadata);
        $this->assertArrayNotHasKey('oauth', $metadata);
        $this->assertArrayNotHasKey('token', $metadata);
        $this->assertSame([
            'upsert_procurement_process',
            'update_procurement_process',
            'sync_process_requirements',
            'update_process_requirement',
            'add_process_document',
            'update_process_document',
            'log_process_activity',
        ], $metadata['exclude']);
        $this->assertSame('KIAS', $tool->name);
        $this->assertSame($integration->id, $tool->integrations_id);
        $this->assertSame(ToolTypeEnum::MCP->value, $tool->tool_type);
        $this->assertSame(['neuron'], json_decode($tool->frameworks, true));
        $this->assertNull($tool->handler);
        $this->assertSame(0, $tool->apps_id);
        $this->assertSame(1, $tool->is_active);
        $this->assertSame(0, $tool->is_deleted);
        $this->assertSame('1.0.0', $tool->version);
        $this->assertStringContainsString('/mcp/kias', $tool->description);
        $this->assertStringContainsString('idempotency_key', $tool->description);
    }

    public function testMigrationsAreIdempotentAndPreserveExistingConfiguration(): void
    {
        $this->integrationMigration()->up();
        $this->toolMigration()->up();
        DB::connection('workflow')->table('integrations')->update(['metadata' => json_encode(['operator' => 'configured'])]);
        $integrationId = DB::connection('workflow')->table('integrations')->value('id');
        $toolId = DB::connection('intelligence')->table('nervous_system_tools')->value('id');

        $this->integrationMigration()->up();
        $this->toolMigration()->up();

        $this->assertSame(1, DB::connection('workflow')->table('integrations')->count());
        $this->assertSame(1, DB::connection('intelligence')->table('nervous_system_tools')->count());
        $this->assertSame($integrationId, DB::connection('workflow')->table('integrations')->value('id'));
        $this->assertSame($toolId, DB::connection('intelligence')->table('nervous_system_tools')->value('id'));
        $this->assertSame(['operator' => 'configured'], json_decode(DB::connection('workflow')->table('integrations')->value('metadata'), true));
    }

    public function testCatalogRequiresGlobalIntegration(): void
    {
        $this->toolMigration()->up();
        $this->assertSame(0, DB::connection('intelligence')->table('nervous_system_tools')->count());

        $this->integrationMigration()->up();
        DB::connection('workflow')->table('integrations')->update(['apps_id' => 123]);
        $this->toolMigration()->up();
        $this->assertSame(0, DB::connection('intelligence')->table('nervous_system_tools')->count());
    }

    public function testRollbackOnlyRemovesGlobalMcpRows(): void
    {
        $this->integrationMigration()->up();
        $this->toolMigration()->up();
        $integration = (array) DB::connection('workflow')->table('integrations')->sole();
        $tool = (array) DB::connection('intelligence')->table('nervous_system_tools')->sole();
        unset($integration['id'], $tool['id']);

        DB::connection('workflow')->table('integrations')->insert([...$integration, 'uuid' => (string) Str::uuid(), 'apps_id' => 123]);
        DB::connection('workflow')->table('integrations')->insert([...$integration, 'uuid' => (string) Str::uuid(), 'type' => 'key']);
        DB::connection('intelligence')->table('nervous_system_tools')->insert([...$tool, 'uuid' => (string) Str::uuid(), 'apps_id' => 123]);
        DB::connection('intelligence')->table('nervous_system_tools')->insert([...$tool, 'uuid' => (string) Str::uuid(), 'tool_type' => 'custom']);

        $this->toolMigration()->down();
        $this->integrationMigration()->down();
        $this->toolMigration()->down();
        $this->integrationMigration()->down();

        $this->assertSame(2, DB::connection('workflow')->table('integrations')->count());
        $this->assertSame(2, DB::connection('intelligence')->table('nervous_system_tools')->count());
        $this->assertSame(1, DB::connection('workflow')->table('integrations')->where('apps_id', 123)->count());
        $this->assertSame(1, DB::connection('intelligence')->table('nervous_system_tools')->where('apps_id', 123)->count());
    }

    public function testTenantRowsDoNotPreventGlobalRegistration(): void
    {
        $this->integrationMigration()->up();
        $this->toolMigration()->up();
        DB::connection('workflow')->table('integrations')->update(['apps_id' => 123]);
        DB::connection('intelligence')->table('nervous_system_tools')->update(['apps_id' => 123]);

        $this->integrationMigration()->up();
        $this->toolMigration()->up();

        $this->assertSame(2, DB::connection('workflow')->table('integrations')->count());
        $this->assertSame(2, DB::connection('intelligence')->table('nervous_system_tools')->count());
        $this->assertSame(1, DB::connection('workflow')->table('integrations')->where('apps_id', 0)->count());
        $this->assertSame(1, DB::connection('intelligence')->table('nervous_system_tools')->where('apps_id', 0)->count());
    }

    private function integrationMigration(): Migration
    {
        return require dirname(__DIR__, 3) . '/database/migrations/Workflow/2026_10_09_170000_add_kias_mcp_integration.php';
    }

    private function toolMigration(): Migration
    {
        return require dirname(__DIR__, 3) . '/database/migrations/Intelligence/2026_10_09_170001_add_kias_mcp_tool.php';
    }
}
