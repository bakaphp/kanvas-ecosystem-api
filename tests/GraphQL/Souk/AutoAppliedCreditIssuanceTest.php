<?php

declare(strict_types=1);

namespace Tests\GraphQL\Souk;

use Database\Seeders\Souk\DiscountTypeSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Testing\TestResponse;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Enums\AppEnums;
use Kanvas\Souk\Discounts\Enums\DiscountTypeEnum;
use Kanvas\Souk\Discounts\Models\Discount;
use Kanvas\Souk\Discounts\Models\DiscountType;
use Kanvas\Users\Models\UserCompanyApps;
use Tests\TestCase;

final class AutoAppliedCreditIssuanceTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'ecosystem', 'commerce'];

    private const string MUTATION = '
        mutation createDiscount($input: DiscountInput!) {
            createDiscount(input: $input) {
                id
                code
                value
                is_percentage
                usage_limit
                min_order_value
                max_discount_amount
            }
        }
    ';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DiscountTypeSeeder::class);
    }

    public function testCreditIsForcedToSingleUseFixedAmountWithoutCode(): void
    {
        $response = $this->issueCredit([
            'value' => 150,
            'code' => 'SHOULD-BE-DROPPED',
            'is_percentage' => true,
            'usage_limit' => 10,
            'min_order_value' => 500,
            'max_discount_amount' => 10,
        ]);

        $response->assertSuccessful();
        $credit = $response->json('data.createDiscount');

        $this->assertNull($credit['code']);
        $this->assertFalse($credit['is_percentage']);
        $this->assertSame(1, $credit['usage_limit']);
        $this->assertSame(150.0, (float) $credit['value']);
        $this->assertNull($credit['min_order_value']);
        $this->assertNull($credit['max_discount_amount']);
    }

    public function testEachCreditIsANewRowEvenForTheSameCompany(): void
    {
        $first = $this->issueCredit(['value' => 100])->json('data.createDiscount.id');
        $second = $this->issueCredit(['value' => 50])->json('data.createDiscount.id');

        $this->assertNotSame($first, $second);
        $this->assertSame(50.0, (float) Discount::findOrFail($second)->value);
    }

    public function testCreditCanBeIssuedToAnotherCompanyOfTheApp(): void
    {
        $client = Companies::factory()->create(['users_id' => auth()->user()->getId()]);

        $id = $this->issueCredit(['value' => 75, 'companies_id' => $client->getId()])
            ->json('data.createDiscount.id');

        $this->assertSame($client->getId(), (int) Discount::findOrFail($id)->companies_id);
    }

    public function testCreditCannotBeIssuedToACompanyOutsideTheApp(): void
    {
        $foreign = Companies::factory()->create(['users_id' => auth()->user()->getId()]);
        UserCompanyApps::where('companies_id', $foreign->getId())->delete();

        $response = $this->issueCredit(['value' => 75, 'companies_id' => $foreign->getId()]);

        $this->assertStringContainsString("doesn't have access to this app", $response->json('errors.0.message'));
        $this->assertSame(0, Discount::where('companies_id', $foreign->getId())->count());
    }

    public function testACreditMustBeGreaterThanZero(): void
    {
        $response = $this->issueCredit(['value' => 0]);

        $this->assertStringContainsString('greater than zero', $response->json('errors.0.message'));
    }

    public function testPromoDiscountsKeepTheExistingBehaviour(): void
    {
        $promoType = DiscountType::getByName(DiscountTypeEnum::FIXED_AMOUNT->label());

        $response = $this->createDiscount([
            'discount_type_id' => $promoType->getId(),
            'value' => 20,
            'code' => 'PROMO20',
            'usage_limit' => 10,
        ]);

        $response->assertSuccessful();
        $this->assertSame('PROMO20', $response->json('data.createDiscount.code'));
        $this->assertSame(10, $response->json('data.createDiscount.usage_limit'));
    }

    private function issueCredit(array $overrides): TestResponse
    {
        $creditType = DiscountType::getByName(DiscountTypeEnum::AUTO_APPLIED_CREDIT->label());

        return $this->createDiscount(['discount_type_id' => $creditType->getId(), ...$overrides]);
    }

    private function createDiscount(array $overrides): TestResponse
    {
        $app = app(Apps::class);

        return $this->graphQL(self::MUTATION, [
            'input' => [
                'name' => 'Credit for unavailable items',
                'description' => 'Issued after order #123 shipped short',
                ...$overrides,
            ],
        ], [], [
            AppEnums::KANVAS_APP_KEY_HEADER->getValue() => $app->keys()->first()->client_secret_id,
        ]);
    }
}
