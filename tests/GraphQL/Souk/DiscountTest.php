<?php

declare(strict_types=1);

namespace Tests\GraphQL\Souk;

use Kanvas\Apps\Models\Apps;
use Kanvas\Enums\AppEnums;
use Kanvas\Souk\Discounts\Models\Discount;
use Kanvas\Souk\Discounts\Models\DiscountType;
use Tests\TestCase;

class DiscountTest extends TestCase
{
    public function testCreateDiscount(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        // Create a discount type first
        $discountType = DiscountType::factory()->withAppId(0)->create([
            'name' => 'Percentage',
        ]);

        $response = $this->graphQL('
            mutation createDiscount($input: DiscountInput!) {
                createDiscount(input: $input) {
                    id
                    uuid
                    name
                    code
                    value
                    is_percentage
                    is_active
                }
            }
        ', [
            'input' => [
                'name' => '10% Off Summer Sale',
                'description' => 'Summer discount for all products',
                'discount_type_id' => $discountType->id,
                'value' => 10,
                'is_percentage' => true,
                'code' => 'SUMMER10',
                'min_order_value' => 50,
                'max_discount_amount' => 100,
                'start_date' => now()->format('Y-m-d H:i:s'),
                'end_date' => now()->addMonth()->format('Y-m-d H:i:s'),
                'is_active' => true,
                'usage_limit' => 100,
                'is_one_per_customer' => true,
            ],
        ], [], [
            AppEnums::KANVAS_APP_KEY_HEADER->getValue() => $app->keys()->first()->client_secret_id,
        ]);

        $response->assertSuccessful();

        $response->assertJsonStructure([
            'data' => [
                'createDiscount' => [
                    'id',
                    'uuid',
                    'name',
                    'code',
                    'value',
                    'is_percentage',
                    'is_active',
                ],
            ],
        ]);

        $this->assertEquals('10% Off Summer Sale', $response->json('data.createDiscount.name'));
        $this->assertEquals('SUMMER10', $response->json('data.createDiscount.code'));
        $this->assertEquals(10, $response->json('data.createDiscount.value'));
        $this->assertTrue($response->json('data.createDiscount.is_percentage'));
    }

    public function testGetActiveDiscounts(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        // Create some discounts using factories
        Discount::factory()->count(3)->create([
            'apps_id' => $app->getId(),
            'companies_id' => $company->getId(),
            'is_active' => true,
            'start_date' => now()->subDay(),
            'end_date' => now()->addMonth(),
        ]);

        Discount::factory()->count(2)->create([
            'apps_id' => $app->getId(),
            'companies_id' => $company->getId(),
            'is_active' => false,
        ]);

        // Use GraphQL where condition to filter active discounts
        $response = $this->graphQL('
            query {
                discounts(where: { column: IS_ACTIVE, operator: EQ, value: true }) {
                    data {
                        id
                        name
                        is_active
                    }
                }
            }
        ', [], [], [
            AppEnums::KANVAS_APP_KEY_HEADER->getValue() => $app->keys()->first()->client_secret_id,
        ]);

        $response->assertSuccessful();
        $this->assertGreaterThan(0, count($response->json('data.discounts.data')));

        // Verify all returned discounts are active
        $discounts = $response->json('data.discounts.data');
        foreach ($discounts as $discount) {
            $this->assertTrue($discount['is_active']);
        }
    }

    public function testFindDiscountByCode(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $discount = Discount::factory()->create([
            'apps_id' => $app->getId(),
            'companies_id' => $company->getId(),
            'code' => 'TESTCODE',
            'is_active' => true,
        ]);

        // Use GraphQL where condition to find discount by code
        $response = $this->graphQL('
            query {
                discounts(where: { column: CODE, operator: EQ, value: "TESTCODE" }) {
                    data {
                        id
                        name
                        code
                    }
                }
            }
        ', [], [], [
            AppEnums::KANVAS_APP_KEY_HEADER->getValue() => $app->keys()->first()->client_secret_id,
        ]);

        $response->assertSuccessful();
        $discounts = $response->json('data.discounts.data');
        $this->assertEquals('TESTCODE', $discounts[0]['code']);
    }

    public function testGetDiscountsByValueRange(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        // Create discounts with different values
        Discount::factory()->create([
            'apps_id' => $app->getId(),
            'companies_id' => $company->getId(),
            'value' => 5,
        ]);

        Discount::factory()->create([
            'apps_id' => $app->getId(),
            'companies_id' => $company->getId(),
            'value' => 15,
        ]);

        Discount::factory()->create([
            'apps_id' => $app->getId(),
            'companies_id' => $company->getId(),
            'value' => 25,
        ]);

        // Use GraphQL where condition to find discounts with value >= 10
        $response = $this->graphQL('
            query {
                discounts(where: { column: VALUE, operator: GTE, value: 10 }) {
                    data {
                        id
                        value
                    }
                }
            }
        ', [], [], [
            AppEnums::KANVAS_APP_KEY_HEADER->getValue() => $app->keys()->first()->client_secret_id,
        ]);

        $response->assertSuccessful();
        $discounts = $response->json('data.discounts.data');

        // Verify all returned discounts have value >= 10
        foreach ($discounts as $discount) {
            $this->assertGreaterThanOrEqual(10, $discount['value']);
        }
    }

    public function testGetDiscountsByDiscountType(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $discountType = DiscountType::factory()->withAppId(0)->create([
            'name' => 'Fixed Amount',
        ]);

        // Create discounts with specific discount type
        Discount::factory()->count(2)->create([
            'apps_id' => $app->getId(),
            'companies_id' => $company->getId(),
            'discount_type_id' => $discountType->id,
        ]);

        // Use GraphQL where condition to find discounts by type
        $response = $this->graphQL('
            query {
                discounts(where: { column: DISCOUNT_TYPE_ID, operator: EQ, value: ' . $discountType->id . ' }) {
                    data {
                        id
                        discount_type {
                            id
                            name
                        }
                    }
                }
            }
        ', [], [], [
            AppEnums::KANVAS_APP_KEY_HEADER->getValue() => $app->keys()->first()->client_secret_id,
        ]);

        $response->assertSuccessful();
        $discounts = $response->json('data.discounts.data');

        // Verify all returned discounts have the correct discount type
        foreach ($discounts as $discount) {
            $this->assertEquals($discountType->id, $discount['discount_type']['id']);
            $this->assertEquals('Fixed Amount', $discount['discount_type']['name']);
        }
    }

    public function testDeleteDiscount(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $discount = Discount::factory()->create([
            'apps_id' => $app->getId(),
            'companies_id' => $company->getId(),
        ]);

        $response = $this->graphQL('
            mutation deleteDiscount($id: ID!) {
                deleteDiscount(id: $id)
            }
        ', [
            'id' => $discount->id,
        ], [], [
            AppEnums::KANVAS_APP_KEY_HEADER->getValue() => $app->keys()->first()->client_secret_id,
        ]);

        $response->assertSuccessful();
        $this->assertTrue($response->json('data.deleteDiscount'));

        // Check that discount is soft deleted
        $this->assertTrue($discount->fresh()->is_deleted);
    }
}
