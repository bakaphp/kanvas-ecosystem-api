<?php

declare(strict_types=1);

namespace Tests\GraphQL\Souk;

use Kanvas\Souk\Taxes\Enums\ConfigurationEnum;
use Kanvas\Souk\Taxes\Models\TaxRegistration;
use Kanvas\Souk\Taxes\Models\TaxRoutingRule;
use PHPUnit\Framework\Attributes\Group;

#[Group('serial')]
final class ShippingCartTaxTest extends ShippingOrderBase
{
    private TaxRegistration $registration;
    private TaxRoutingRule $rule;

    public function setUp(): void
    {
        parent::setUp();

        $this->apps->set(ConfigurationEnum::TAX_ENGINE_ENABLED->value, 1);

        $this->registration = new TaxRegistration();
        $this->registration->apps_id = $this->apps->getId();
        $this->registration->jurisdiction_country = 'US';
        $this->registration->provider = 'manual';
        $this->registration->effective_from = '2026-01-01';
        $this->registration->is_active = true;
        $this->registration->saveOrFail();

        $this->rule = new TaxRoutingRule();
        $this->rule->apps_id = $this->apps->getId();
        $this->rule->condition_country = 'US';
        $this->rule->provider = 'manual';
        $this->rule->rate = 0.10;
        $this->rule->priority = 100;
        $this->rule->tax_shipping = false;
        $this->rule->is_active = true;
        $this->rule->saveOrFail();
    }

    public function tearDown(): void
    {
        $this->apps->set(ConfigurationEnum::TAX_ENGINE_ENABLED->value, 0);
        $this->rule->forceDelete();
        $this->registration->forceDelete();

        parent::tearDown();
    }

    public function testApplyingAShippingQuoteCalculatesTax(): void
    {
        $this->addToCart()->assertSuccessful();
        $this->assertNotSame('calculated', $this->cartTax()['tax']['status'] ?? null);

        $this->applyShippingQuote()->assertSuccessful();

        $cart = $this->cartTax();
        $this->assertSame('calculated', $cart['tax']['status']);
        $this->assertGreaterThan(0.0, (float) $cart['total_tax']);
    }

    public function testChangingQuantityRecalculatesTax(): void
    {
        $this->addToCart()->assertSuccessful();
        $this->applyShippingQuote()->assertSuccessful();
        $taxBefore = (float) $this->cartTax()['total_tax'];

        $this->updateCartQuantity(2)->assertSuccessful();

        $cart = $this->cartTax();
        $this->assertGreaterThan($taxBefore, (float) $cart['total_tax']);
        $this->assertEqualsWithDelta((float) $cart['subtotal'] * 0.10, (float) $cart['total_tax'], 0.01);
    }

    public function testRemovingTheLastItemClearsTax(): void
    {
        $this->addToCart()->assertSuccessful();
        $this->applyShippingQuote()->assertSuccessful();

        $this->removeFromCart()->assertSuccessful();

        $cart = $this->cartTax();
        $this->assertNull($cart['tax']);
        $this->assertEqualsWithDelta(0.0, (float) $cart['total_tax'], 0.001);
    }

    private function cartTax(): array
    {
        return $this->graphQL('
            query {
                cart {
                    subtotal
                    total_tax
                    tax {
                        status
                        amount
                    }
                }
            }
        ', [], [], $this->cartHeaders())->json('data.cart');
    }
}
