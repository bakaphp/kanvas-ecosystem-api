<?php

declare(strict_types=1);

namespace Tests\Souk\Taxes;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Kanvas\Apps\Models\Apps;
use Kanvas\Souk\Cart\Services\CartService;
use Kanvas\Souk\Taxes\Actions\EstimateCartTaxAction;
use Kanvas\Souk\Taxes\Enums\ConfigurationEnum;
use Kanvas\Souk\Taxes\Enums\TaxConditionEnum;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;
use Wearepixel\Cart\CartCondition;

#[Group('serial')]
final class EstimateCartTaxActionTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'commerce', 'ecosystem'];

    private Apps $kanvasApp;

    protected function setUp(): void
    {
        parent::setUp();

        $this->kanvasApp = app(Apps::class);
        $this->kanvasApp->set(ConfigurationEnum::TAX_ENGINE_ENABLED->value, 1);
        $this->kanvasApp->set(ConfigurationEnum::TAX_FAIL_OPEN->value, 1);
    }

    protected function tearDown(): void
    {
        $this->kanvasApp->set(ConfigurationEnum::TAX_ENGINE_ENABLED->value, 0);
        $this->kanvasApp->set(ConfigurationEnum::TAX_FAIL_OPEN->value, 0);

        parent::tearDown();
    }

    private function makeCart(): \Wearepixel\Cart\Cart
    {
        $cart = app('cart')->session('test-session-' . uniqid());
        $cart->clear();
        $cart->clearAllConditions();

        return $cart;
    }

    private function addShippingCondition(\Wearepixel\Cart\Cart $cart, string $country = 'DO'): void
    {
        $cart->condition(new CartCondition([
            'name' => 'Shipping',
            'type' => 'shipping',
            'target' => 'subtotal',
            'value' => '+10.00',
            'order' => 40,
            'attributes' => [
                'provider' => 'test',
                'destination' => [
                    'country' => $country,
                    'city' => 'Santo Domingo',
                    'postal_code' => null,
                ],
            ],
        ]));
    }

    public function testTaxEngineDisabledDoesNotWriteCondition(): void
    {
        $this->kanvasApp->set(ConfigurationEnum::TAX_ENGINE_ENABLED->value, 0);
        $cart = $this->makeCart();

        new EstimateCartTaxAction($cart, $this->kanvasApp)->execute();

        $this->assertNull($cart->getCondition(TaxConditionEnum::NAME->value));
    }

    public function testEmptyCartRemovesTaxCondition(): void
    {
        $cart = $this->makeCart();
        $cart->condition(new CartCondition([
            'name' => TaxConditionEnum::NAME->value,
            'type' => TaxConditionEnum::TYPE->value,
            'target' => 'total',
            'value' => '+18.00',
        ]));

        new EstimateCartTaxAction($cart, $this->kanvasApp)->execute();

        $this->assertNull($cart->getCondition(TaxConditionEnum::NAME->value));
    }

    public function testNoShippingAddressWritesUnsupportedCondition(): void
    {
        $cart = $this->makeCart();
        $cart->add([
            'id' => 99999,
            'name' => 'Test Product',
            'price' => 100.00,
            'quantity' => 1,
            'attributes' => [],
        ]);

        new EstimateCartTaxAction($cart, $this->kanvasApp)->execute();

        $taxCondition = $cart->getCondition(TaxConditionEnum::NAME->value);

        $this->assertNotNull($taxCondition);
        $this->assertSame(TaxConditionEnum::TYPE->value, $taxCondition->getType());
        $this->assertSame('unsupported', $taxCondition->getAttributes()['status']);
    }

    public function testUnsupportedConditionHasZeroValue(): void
    {
        $cart = $this->makeCart();
        $cart->add([
            'id' => 99999,
            'name' => 'Test Product',
            'price' => 100.00,
            'quantity' => 1,
            'attributes' => [],
        ]);

        new EstimateCartTaxAction($cart, $this->kanvasApp)->execute();

        $taxCondition = $cart->getCondition(TaxConditionEnum::NAME->value);

        $this->assertSame('+0', $taxCondition->getValue());
    }

    public function testFailOpenWithUnknownCountryWritesFailedOrUnsupportedCondition(): void
    {
        $this->kanvasApp->set(ConfigurationEnum::TAX_FAIL_OPEN->value, 1);
        $cart = $this->makeCart();
        $cart->add([
            'id' => 99999,
            'name' => 'Test Product',
            'price' => 100.00,
            'quantity' => 1,
            'attributes' => [],
        ]);

        $this->addShippingCondition($cart, 'XX');

        new EstimateCartTaxAction($cart, $this->kanvasApp)->execute();

        $taxCondition = $cart->getCondition(TaxConditionEnum::NAME->value);

        $this->assertNotNull($taxCondition);
        $this->assertContains($taxCondition->getAttributes()['status'], ['unsupported', 'failed']);
    }

    public function testTaxConditionIsIdempotent(): void
    {
        $cart = $this->makeCart();
        $cart->add([
            'id' => 99999,
            'name' => 'Test Product',
            'price' => 100.00,
            'quantity' => 1,
            'attributes' => [],
        ]);

        new EstimateCartTaxAction($cart, $this->kanvasApp)->execute();
        new EstimateCartTaxAction($cart, $this->kanvasApp)->execute();

        $taxConditions = $cart->getConditionsByType(TaxConditionEnum::TYPE->value);

        $this->assertCount(1, $taxConditions);
    }

    public function testConditionHasInputHashAndCalculatedAt(): void
    {
        $cart = $this->makeCart();
        $cart->add([
            'id' => 99999,
            'name' => 'Test Product',
            'price' => 100.00,
            'quantity' => 1,
            'attributes' => [],
        ]);

        new EstimateCartTaxAction($cart, $this->kanvasApp)->execute();

        $taxCondition = $cart->getCondition(TaxConditionEnum::NAME->value);
        $attributes = $taxCondition->getAttributes();

        $this->assertArrayHasKey('input_hash', $attributes);
        $this->assertArrayHasKey('calculated_at', $attributes);
        $this->assertNotEmpty($attributes['input_hash']);
    }

    public function testConditionHasOrder50(): void
    {
        $cart = $this->makeCart();
        $cart->add([
            'id' => 99999,
            'name' => 'Test Product',
            'price' => 100.00,
            'quantity' => 1,
            'attributes' => [],
        ]);

        new EstimateCartTaxAction($cart, $this->kanvasApp)->execute();

        $taxCondition = $cart->getCondition(TaxConditionEnum::NAME->value);

        $this->assertSame(50, $taxCondition->getOrder());
    }

    public function testTaxConditionDoesNotInflateDiscountTotal(): void
    {
        $cart = $this->makeCart();
        $cart->add([
            'id' => 99999,
            'name' => 'Test Product',
            'price' => 100.00,
            'quantity' => 1,
            'attributes' => [],
        ]);

        new EstimateCartTaxAction($cart, $this->kanvasApp)->execute();

        $taxCondition = $cart->getCondition(TaxConditionEnum::NAME->value);

        $this->assertSame(TaxConditionEnum::TYPE->value, $taxCondition->getType());
        $this->assertNotSame('discount', $taxCondition->getType());
    }

    public function testGetCartExcludesTaxFromDiscountsAndPopulatesTaxFields(): void
    {
        $cart = $this->makeCart();

        $cart->condition(new CartCondition([
            'name' => 'SAVE10',
            'type' => 'discount',
            'target' => 'subtotal',
            'value' => '-10.00',
            'order' => 1,
            'attributes' => [],
        ]));

        $cart->condition(new CartCondition([
            'name' => TaxConditionEnum::NAME->value,
            'type' => TaxConditionEnum::TYPE->value,
            'target' => 'total',
            'value' => '+18.00',
            'order' => 50,
            'attributes' => [
                'status' => 'calculated',
                'provider' => 'manual',
                'amount' => 18.00,
                'mode' => 'exclusive',
                'components' => [
                    ['line_id' => 'line-1', 'rate' => 0.18, 'amount' => 18.00, 'treatment' => 'taxable'],
                ],
            ],
        ]));

        $cartService = new CartService($cart);
        $result = $cartService->getCart();

        $this->assertArrayHasKey('discounts', $result);
        $this->assertArrayHasKey('tax', $result);
        $this->assertArrayHasKey('total_tax', $result);

        foreach ($result['discounts'] as $discount) {
            $this->assertNotSame(TaxConditionEnum::NAME->value, $discount['code'] ?? '');
        }

        $discountCodes = array_column($result['discounts'], 'code');
        $this->assertNotContains(TaxConditionEnum::NAME->value, $discountCodes);
        $this->assertContains('SAVE10', $discountCodes);

        $this->assertSame('calculated', $result['tax']['status']);
        $this->assertSame('exclusive', $result['tax']['mode']);
        $this->assertNotEmpty($result['tax']['components']);
        $this->assertGreaterThan(0.0, (float) $result['total_tax']);
    }

    public function testTaxedTreatmentWritesCalculatedConditionWithCorrectValue(): void
    {
        $appId = $this->kanvasApp->getId();

        DB::connection('commerce')->table('tax_registrations')->insert([
            'uuid' => Str::uuid()->toString(),
            'apps_id' => $appId,
            'jurisdiction_country' => 'DO',
            'jurisdiction_state' => null,
            'provider' => 'manual',
            'effective_from' => '2026-01-01',
            'effective_to' => null,
            'is_active' => 1,
            'is_deleted' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::connection('commerce')->table('tax_routing_rules')->insert([
            'uuid' => Str::uuid()->toString(),
            'apps_id' => $appId,
            'condition_country' => 'DO',
            'provider' => 'manual',
            'rate' => 0.18,
            'priority' => 100,
            'tax_shipping' => 0,
            'is_active' => 1,
            'is_deleted' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $cart = $this->makeCart();
        $cart->add([
            'id' => 99999,
            'name' => 'Test Product',
            'price' => 100.00,
            'quantity' => 1,
            'attributes' => [],
        ]);
        $this->addShippingCondition($cart, 'DO');

        new EstimateCartTaxAction($cart, $this->kanvasApp)->execute();

        $taxCondition = $cart->getCondition(TaxConditionEnum::NAME->value);

        $this->assertNotNull($taxCondition);
        $attributes = $taxCondition->getAttributes();

        $this->assertSame('calculated', $attributes['status']);
        $this->assertSame('+18.00', $taxCondition->getValue());
        $this->assertNotEmpty($attributes['components']);
        $this->assertSame('exclusive', $attributes['mode']);
    }
}
