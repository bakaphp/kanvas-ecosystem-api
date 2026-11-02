<?php

declare(strict_types=1);

namespace Tests\GraphQL\Ecosystem;

use App\GraphQL\Ecosystem\Handlers\CompanyScopedCustomFieldsHandler;
use Illuminate\Database\Eloquent\Model;
use Kanvas\Apps\Models\AppKey;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Inventory\Products\Models\Products;
use Tests\TestCase;

final class CompanyScopedCustomFieldsHandlerTest extends TestCase
{
    private const string FIELD = 'ZOHO_LEAD_ID';

    public function testSubqueryIsPinnedToTheActingCompany(): void
    {
        $query = Lead::query();
        app(CompanyScopedCustomFieldsHandler::class)($query, $this->hasCustomFieldsPayload('123'));

        $this->assertMatchesRegularExpression(
            '/`apps_custom_fields`\.`companies_id` = \?/',
            $query->toSql()
        );
        $this->assertContains(auth()->user()->getCurrentCompany()->getId(), $query->getBindings());
        $this->assertContains(self::FIELD, $query->getBindings());
    }

    public function testSubqueryIsAsWideAsItsParentForAnAppKeyWithoutABranch(): void
    {
        app()->instance(AppKey::class, new AppKey());

        $query = Lead::query();
        app(CompanyScopedCustomFieldsHandler::class)($query, $this->hasCustomFieldsPayload('123'));

        $this->assertMatchesRegularExpression(
            '/`apps_custom_fields`\.`companies_id` > \?/',
            $query->toSql()
        );
        $this->assertDoesNotMatchRegularExpression(
            '/`apps_custom_fields`\.`companies_id` = \?/',
            $query->toSql()
        );
    }

    public function testLeadsQueryFindsTheLeadByCustomFieldWithoutLeakingAcrossCompanies(): void
    {
        $this->assertQueryReturnsOnlyTheActingCompanyEntity(
            'leads',
            fn (int $companyId) => Lead::factory()->create(['companies_id' => $companyId]),
        );
    }

    public function testPeopleQueryFindsThePersonByCustomFieldWithoutLeakingAcrossCompanies(): void
    {
        $this->assertQueryReturnsOnlyTheActingCompanyEntity(
            'peoples',
            fn (int $companyId) => People::factory()
                ->withAppId(app(Apps::class)->getId())
                ->withCompanyId($companyId)
                ->create(),
        );
    }

    public function testProductsQueryFindsTheProductByCustomFieldWithoutLeakingAcrossCompanies(): void
    {
        $this->assertQueryReturnsOnlyTheActingCompanyEntity(
            'products',
            fn (int $companyId) => Products::factory()->withCompanyId($companyId)->create(),
        );
    }

    /**
     * @param callable(int): Model $create
     */
    private function assertQueryReturnsOnlyTheActingCompanyEntity(string $query, callable $create): void
    {
        $value = (string) fake()->unique()->numberBetween(1_000_000_000, 9_999_999_999);

        $mine = $create(auth()->user()->getCurrentCompany()->getId());
        $mine->set(self::FIELD, $value);

        $theirs = $create(Companies::factory()->create()->getId());
        $theirs->set(self::FIELD, $value);

        $response = $this->graphQL('
            query ($value: Mixed!) {
                ' . $query . '(
                    first: 5
                    hasCustomFields: {
                        AND: [
                            { column: NAME, operator: EQ, value: "' . self::FIELD . '" }
                            { column: VALUE, operator: EQ, value: $value }
                        ]
                    }
                ) {
                    data { id }
                }
            }
        ', ['value' => $value])->assertSuccessful();

        $this->assertSame(
            [(string) $mine->getId()],
            array_column($response->json("data.{$query}.data"), 'id')
        );
    }

    private function hasCustomFieldsPayload(string $value): array
    {
        return [
            'HAS' => [
                'relation' => 'customFields',
                'amount' => 1,
                'operator' => '>=',
                'condition' => [
                    'AND' => [
                        ['column' => 'name', 'operator' => '=', 'value' => self::FIELD],
                        ['column' => 'value', 'operator' => '=', 'value' => $value],
                    ],
                ],
            ],
        ];
    }
}
