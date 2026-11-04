<?php

declare(strict_types=1);

namespace Tests\GraphQL\Souk;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Souk\Taxes\Actions\CreateTaxCategoryAction;
use Kanvas\Souk\Taxes\Models\TaxCategory;
use Kanvas\Souk\Taxes\Models\TaxRoutingRule;
use Tests\TestCase;

final class TaxRoutingRuleCrudTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = ['commerce'];

    private Apps $kanvasApp;

    protected function setUp(): void
    {
        parent::setUp();

        $this->kanvasApp = app(Apps::class);

        if (TaxCategory::findByCode($this->kanvasApp, 'general') === null) {
            new CreateTaxCategoryAction($this->kanvasApp, ['code' => 'general', 'name' => 'General'])->execute();
        }
    }

    public function testCreateAppliesDefaultsForOmittedFields(): void
    {
        $response = $this->graphQL('
            mutation($input: TaxRoutingRuleInput!) {
                createTaxRoutingRule(input: $input) {
                    id
                    name
                    provider
                    condition_country
                    category_codes
                    rate
                    tax_shipping
                    priority
                    is_active
                }
            }
        ', ['input' => [
            'name' => 'DO ITBIS',
            'provider' => 'MANUAL',
            'condition_country' => 'DO',
            'category_codes' => ['general'],
            'rate' => 0.18,
        ]]);

        $response->assertJson([
            'data' => [
                'createTaxRoutingRule' => [
                    'name' => 'DO ITBIS',
                    'provider' => 'MANUAL',
                    'condition_country' => 'DO',
                    'category_codes' => ['general'],
                    'rate' => 0.18,
                    'tax_shipping' => false,
                    'priority' => 100,
                    'is_active' => true,
                ],
            ],
        ]);

        $rule = TaxRoutingRule::find($response->json('data.createTaxRoutingRule.id'));
        $this->assertSame($this->kanvasApp->getId(), (int) $rule->apps_id);
    }

    public function testUpdateOnlyChangesTheFieldsSent(): void
    {
        $rule = $this->createRule(['name' => 'Original', 'rate' => 0.18, 'priority' => 10]);

        $this->graphQL('
            mutation($id: ID!, $input: UpdateTaxRoutingRuleInput!) {
                updateTaxRoutingRule(id: $id, input: $input) { id }
            }
        ', ['id' => $rule->getId(), 'input' => ['rate' => 0.16, 'is_active' => false]])
            ->assertGraphQLErrorFree();

        $rule->refresh();
        $this->assertSame('Original', $rule->name);
        $this->assertEqualsWithDelta(0.16, (float) $rule->rate, 0.0001);
        $this->assertSame(10, (int) $rule->priority);
        $this->assertFalse($rule->is_active);
    }

    public function testDeleteSoftDeletesAndHidesTheRuleFromTheList(): void
    {
        $rule = $this->createRule(['name' => 'To delete ' . uniqid()]);

        $this->graphQL('
            mutation($id: ID!) {
                deleteTaxRoutingRule(id: $id)
            }
        ', ['id' => $rule->getId()])->assertJson(['data' => ['deleteTaxRoutingRule' => true]]);

        $this->assertTrue((bool) $rule->refresh()->getRawOriginal('is_deleted'));

        $this->graphQL('
            query($where: QueryTaxRoutingRulesWhereWhereConditions) {
                taxRoutingRules(where: $where) { data { id } }
            }
        ', ['where' => ['column' => 'ID', 'operator' => 'EQ', 'value' => $rule->getId()]])
            ->assertJsonCount(0, 'data.taxRoutingRules.data');
    }

    public function testRuleOfAnotherAppCannotBeUpdated(): void
    {
        $rule = $this->createRule(['name' => 'Foreign']);
        $rule->apps_id = $this->kanvasApp->getId() + 100000;
        $rule->saveOrFail();

        $this->graphQL('
            mutation($id: ID!, $input: UpdateTaxRoutingRuleInput!) {
                updateTaxRoutingRule(id: $id, input: $input) { id }
            }
        ', ['id' => $rule->getId(), 'input' => ['name' => 'Hijacked']])
            ->assertGraphQLErrorMessage("No query results for model [Kanvas\\Souk\\Taxes\\Models\\TaxRoutingRule]. {$rule->getId()}");

        $this->assertSame('Foreign', $rule->refresh()->name);
    }

    public function testUnknownProviderIsRejected(): void
    {
        $error = $this->graphQL('
            mutation($input: TaxRoutingRuleInput!) {
                createTaxRoutingRule(input: $input) { id }
            }
        ', ['input' => [
            'name' => 'Typo',
            'provider' => 'manual_rate',
            'rate' => 0.18,
        ]])->json('errors.0.message');

        $this->assertStringContainsString('TaxProviderEnum', (string) $error);

        $this->assertFalse(TaxRoutingRule::query()->fromApp($this->kanvasApp)->where('name', 'Typo')->exists());
    }

    public function testCategoryCodeOutsideTheCatalogIsRejected(): void
    {
        $error = $this->graphQL('
            mutation($input: TaxRoutingRuleInput!) {
                createTaxRoutingRule(input: $input) { id }
            }
        ', ['input' => [
            'name' => 'Typo category',
            'provider' => 'MANUAL',
            'category_codes' => ['general', 'tabacco'],
            'rate' => 0.18,
        ]])->json('errors.0.message');

        $this->assertSame('Unknown tax category: tabacco', $error);
    }

    public function testUpdateRejectsACategoryCodeOutsideTheCatalog(): void
    {
        $rule = $this->createRule(['category_codes' => ['general']]);

        $error = $this->graphQL('
            mutation($id: ID!, $input: UpdateTaxRoutingRuleInput!) {
                updateTaxRoutingRule(id: $id, input: $input) { id }
            }
        ', ['id' => $rule->getId(), 'input' => ['category_codes' => ['tabacco']]])->json('errors.0.message');

        $this->assertSame('Unknown tax category: tabacco', $error);
        $this->assertSame(['general'], $rule->refresh()->category_codes);
    }

    private function createRule(array $overrides = []): TaxRoutingRule
    {
        $rule = new TaxRoutingRule();
        $rule->apps_id = $this->kanvasApp->getId();
        $rule->name = 'Rule';
        $rule->provider = 'manual';
        $rule->rate = 0.18;
        $rule->tax_shipping = false;
        $rule->priority = 100;
        $rule->is_active = true;
        $rule->fill($overrides);
        $rule->saveOrFail();

        return $rule;
    }
}
