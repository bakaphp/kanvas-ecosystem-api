<?php

declare(strict_types=1);

namespace Tests\GraphQL\NervousSystem;

use Illuminate\Support\Facades\DB;
use Kanvas\Apps\Models\Apps;
use Kanvas\NervousSystem\Capability\Models\ToolCategory;
use Tests\TestCase;

class ToolCategoriesTest extends TestCase
{
    public function testQueryReturnsPlatformSeededCategoriesPlusAppSpecificOnes(): void
    {
        $app = app(Apps::class);

        // Seed an app-specific category so the query has something distinct to return.
        ToolCategory::create([
            'apps_id' => $app->getId(),
            'slug' => 'underwriting-' . uniqid(),
            'name' => 'Underwriting',
            'description' => 'Custom category for underwriting workflows.',
            'display_order' => 200,
            'is_active' => true,
            'is_deleted' => false,
        ]);

        $response = $this->graphQL('
            query {
                nervousSystemToolCategories(first: 50, orderBy: [{ column: DISPLAY_ORDER, order: ASC }]) {
                    data {
                        slug
                        name
                    }
                    paginatorInfo { total }
                }
            }
        ')->assertSuccessful();

        $slugs = array_column($response->json('data.nervousSystemToolCategories.data'), 'slug');

        // Platform seeds (apps_id=0) must always be present.
        $this->assertContains('crm', $slugs);
        $this->assertContains('inventory', $slugs);
        $this->assertContains('social', $slugs);
        $this->assertContains('other', $slugs);
        // App-specific row is visible to this app.
        $this->assertTrue((bool) array_filter($slugs, fn ($s) => str_starts_with($s, 'underwriting-')));
    }

    public function testCreateMutationStoresCategoryScopedToCurrentApp(): void
    {
        $app = app(Apps::class);
        $slug = 'compliance-' . uniqid();

        $this->graphQL('
            mutation($input: CreateNervousSystemToolCategoryInput!) {
                createNervousSystemToolCategory(input: $input) {
                    slug
                    name
                    description
                    display_order
                    is_active
                }
            }
        ', [
            'input' => [
                'slug' => $slug,
                'name' => 'Compliance',
                'description' => 'Tools for KYC / AML / OFAC checks.',
                'icon' => 'shield-check',
                'display_order' => 150,
                'is_active' => true,
            ],
        ])
            ->assertSuccessful()
            ->assertJsonPath('data.createNervousSystemToolCategory.slug', $slug)
            ->assertJsonPath('data.createNervousSystemToolCategory.name', 'Compliance')
            ->assertJsonPath('data.createNervousSystemToolCategory.display_order', 150);

        // Row landed on apps_id of the current app (not 0).
        $this->assertDatabaseHas('nervous_system_tool_categories', [
            'apps_id' => $app->getId(),
            'slug' => $slug,
            'name' => 'Compliance',
        ], 'intelligence');
    }

    public function testToolsCanBeFilteredByCategoryIdViaWhereConditions(): void
    {
        $crmCategory = DB::connection('intelligence')
            ->table('nervous_system_tool_categories')
            ->where('apps_id', 0)
            ->where('slug', 'crm')
            ->first();
        $this->assertNotNull($crmCategory, 'Platform seed for CRM must exist');

        $response = $this->graphQL('
            query($categoryId: Mixed!) {
                nervousSystemTools(
                    first: 5
                    where: { column: TOOL_CATEGORY_ID, operator: EQ, value: $categoryId }
                ) {
                    data {
                        id
                        name
                        category { slug name }
                    }
                    paginatorInfo { total }
                }
            }
        ', ['categoryId' => $crmCategory->id])->assertSuccessful();

        // Every returned tool's category relation must match the CRM filter.
        $rows = $response->json('data.nervousSystemTools.data');
        foreach ($rows as $row) {
            $this->assertSame('crm', $row['category']['slug'] ?? null);
        }
    }
}
