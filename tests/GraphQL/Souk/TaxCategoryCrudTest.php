<?php

declare(strict_types=1);

namespace Tests\GraphQL\Souk;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Testing\TestResponse;
use Kanvas\Apps\Models\Apps;
use Kanvas\Souk\Taxes\Models\TaxCategory;
use Kanvas\Souk\Taxes\Models\TaxRoutingRule;
use Tests\TestCase;

final class TaxCategoryCrudTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = ['commerce'];

    private Apps $kanvasApp;

    protected function setUp(): void
    {
        parent::setUp();

        $this->kanvasApp = app(Apps::class);
    }

    public function testCreateNormalizesTheCode(): void
    {
        $suffix = uniqid();

        $response = $this->graphQL('
            mutation($input: TaxCategoryInput!) {
                createTaxCategory(input: $input) { id code name description }
            }
        ', ['input' => [
            'code' => "Tabaco Premium {$suffix}",
            'name' => 'Tabaco premium',
            'description' => 'Cigarros y puros',
        ]]);

        $response->assertJson([
            'data' => [
                'createTaxCategory' => [
                    'code' => "tabaco_premium_{$suffix}",
                    'name' => 'Tabaco premium',
                    'description' => 'Cigarros y puros',
                ],
            ],
        ]);

        $category = TaxCategory::find($response->json('data.createTaxCategory.id'));
        $this->assertSame($this->kanvasApp->getId(), (int) $category->apps_id);
    }

    public function testDuplicateCodeIsRejected(): void
    {
        $category = $this->createCategory();

        $error = $this->createCategoryMutation(['code' => strtoupper($category->code), 'name' => 'Again'])
            ->json('errors.0.message');

        $this->assertSame("Tax category {$category->code} already exists", $error);
    }

    public function testCodeLongerThanTheColumnIsRejected(): void
    {
        $error = $this->createCategoryMutation(['code' => str_repeat('a', 33), 'name' => 'Too long'])
            ->json('errors.0.message');

        $this->assertStringContainsString('between 1 and 32 characters', $error);
    }

    public function testUpdateChangesNameButNeverTheCode(): void
    {
        $category = $this->createCategory();

        $this->graphQL('
            mutation($id: ID!, $input: UpdateTaxCategoryInput!) {
                updateTaxCategory(id: $id, input: $input) { id }
            }
        ', ['id' => $category->getId(), 'input' => ['name' => 'Renamed']])->assertGraphQLErrorFree();

        $category->refresh();
        $this->assertSame('Renamed', $category->name);
    }

    public function testDeleteIsBlockedWhileARateUsesTheCode(): void
    {
        $category = $this->createCategory();

        $rule = new TaxRoutingRule();
        $rule->apps_id = $this->kanvasApp->getId();
        $rule->name = 'Uses category';
        $rule->provider = 'manual';
        $rule->rate = 0.18;
        $rule->category_codes = [$category->code];
        $rule->saveOrFail();

        $error = $this->deleteCategoryMutation($category)->json('errors.0.message');

        $this->assertSame("Tax category {$category->code} is still assigned to tax rates or products", $error);
        $this->assertFalse((bool) $category->refresh()->getRawOriginal('is_deleted'));
    }

    public function testDeleteSoftDeletesAndHidesTheCategoryFromTheList(): void
    {
        $category = $this->createCategory();

        $this->deleteCategoryMutation($category)->assertJson(['data' => ['deleteTaxCategory' => true]]);

        $this->assertTrue((bool) $category->refresh()->getRawOriginal('is_deleted'));
        $this->assertNull(TaxCategory::findByCode($this->kanvasApp, $category->code));

        $this->graphQL('
            query($where: QueryTaxCategoriesWhereWhereConditions) {
                taxCategories(where: $where) { data { id } }
            }
        ', ['where' => ['column' => 'CODE', 'operator' => 'EQ', 'value' => $category->code]])
            ->assertJsonCount(0, 'data.taxCategories.data');
    }

    public function testListOnlyShowsTheCurrentApp(): void
    {
        $own = $this->createCategory();
        $foreign = $this->createCategory();
        $foreign->apps_id = $this->kanvasApp->getId() + 100000;
        $foreign->saveOrFail();

        $codes = collect($this->graphQL('
            query { taxCategories(first: 500) { data { code } } }
        ')->json('data.taxCategories.data'))->pluck('code');

        $this->assertContains($own->code, $codes);
        $this->assertNotContains($foreign->code, $codes);
    }

    private function createCategory(): TaxCategory
    {
        $id = $this->createCategoryMutation(['code' => 'cat_' . uniqid(), 'name' => 'Category'])
            ->json('data.createTaxCategory.id');

        return TaxCategory::findOrFail($id);
    }

    private function createCategoryMutation(array $input): TestResponse
    {
        return $this->graphQL('
            mutation($input: TaxCategoryInput!) {
                createTaxCategory(input: $input) { id }
            }
        ', ['input' => $input]);
    }

    private function deleteCategoryMutation(TaxCategory $category): TestResponse
    {
        return $this->graphQL('
            mutation($id: ID!) {
                deleteTaxCategory(id: $id)
            }
        ', ['id' => $category->getId()]);
    }
}
