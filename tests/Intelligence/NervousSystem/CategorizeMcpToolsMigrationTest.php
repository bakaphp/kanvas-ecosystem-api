<?php

declare(strict_types=1);

namespace Tests\Intelligence\NervousSystem;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Kanvas\Apps\Models\Apps;
use Kanvas\NervousSystem\Capability\Enums\ToolTypeEnum;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Serial: `up()` updates every uncategorized MCP row, including the global (`apps_id = 0`) catalog rows
 * parallel processes take FK parent locks on.
 */
#[Group('serial')]
final class CategorizeMcpToolsMigrationTest extends TestCase
{
    use DatabaseTransactions;

    private const string MIGRATION = 'Intelligence/2026_11_07_100000_categorize_nervous_system_mcp_tools';

    protected $connectionsToTransact = [null, 'intelligence'];

    public function testUpFilesEachUncategorizedMcpToolUnderItsCategory(): void
    {
        $marker = uniqid();
        $expected = [
            'Stripe ' . $marker => 'finance-billing',
            'SAP ' . $marker => 'finance-billing',
            'WhatsApp Business ' . $marker => 'communication',
            'Microsoft 365 ' . $marker => 'communication',
            'Google Ads ' . $marker => 'marketing-analytics',
            'Leads Vault ' . $marker => 'other',
            'Browserbase ' . $marker => 'code',
            'Pipedrive ' . $marker => 'crm',
        ];
        $toolIds = [];
        foreach (array_keys($expected) as $name) {
            $toolIds[$name] = $this->insertTool($name, ToolTypeEnum::MCP);
        }

        $this->migration()->up();

        foreach ($expected as $name => $slug) {
            $this->assertSame($slug, $this->categorySlugOf($toolIds[$name]), $name);
        }
    }

    public function testUpLeavesCategorizedAndNonMcpToolsAlone(): void
    {
        $marker = uniqid();
        $crmId = $this->categoryId('crm');
        $categorizedId = $this->insertTool('Stripe ' . $marker, ToolTypeEnum::MCP, $crmId);
        $customId = $this->insertTool('Stripe custom ' . $marker, ToolTypeEnum::CUSTOM);

        $this->migration()->up();

        $this->assertSame('crm', $this->categorySlugOf($categorizedId));
        $this->assertNull($this->categorySlugOf($customId));
    }

    public function testRunningUpTwiceKeepsOneRowPerNewCategory(): void
    {
        $this->migration()->up();
        $this->migration()->up();

        foreach (['finance-billing', 'marketing-analytics'] as $slug) {
            $count = DB::connection('intelligence')
                ->table('nervous_system_tool_categories')
                ->where('apps_id', 0)
                ->where('slug', $slug)
                ->count();

            $this->assertSame(1, $count, $slug);
        }
    }

    private function migration(): Migration
    {
        return require database_path('migrations/' . self::MIGRATION . '.php');
    }

    private function insertTool(string $name, ToolTypeEnum $type, ?int $categoryId = null): int
    {
        return DB::connection('intelligence')->table('nervous_system_tools')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'apps_id' => app(Apps::class)->getId(),
            'name' => $name,
            'description' => 'Category backfill test fixture.',
            'tool_type' => $type->value,
            'tool_category_id' => $categoryId,
            'frameworks' => json_encode(['neuron']),
            'version' => '1.0.0',
            'is_active' => true,
            'is_deleted' => false,
            'created_at' => now(),
        ]);
    }

    private function categoryId(string $slug): int
    {
        return (int) DB::connection('intelligence')
            ->table('nervous_system_tool_categories')
            ->where('apps_id', 0)
            ->where('slug', $slug)
            ->value('id');
    }

    private function categorySlugOf(int $toolId): ?string
    {
        return DB::connection('intelligence')
            ->table('nervous_system_tools as tools')
            ->leftJoin('nervous_system_tool_categories as categories', 'categories.id', '=', 'tools.tool_category_id')
            ->where('tools.id', $toolId)
            ->value('categories.slug');
    }
}
