<?php

declare(strict_types=1);

namespace Tests\GraphQL\Souk;

use Illuminate\Support\Facades\Queue;
use Kanvas\Guild\Customers\Actions\CreatePeopleFromUserAction;
use Kanvas\Souk\Orders\Enums\OrderStatusEnum;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Taxes\Actions\CompleteCheckoutFromSnapshotAction;
use Kanvas\Souk\Taxes\Actions\FreezeCartTaxAction;
use Kanvas\Souk\Taxes\Enums\ConfigurationEnum;
use Kanvas\Souk\Taxes\Enums\GatewayAttemptStatusEnum;
use Kanvas\Souk\Taxes\Enums\TaxProviderEnum;
use Kanvas\Souk\Taxes\Enums\TaxStatusEnum;
use Kanvas\Souk\Taxes\Jobs\CommitTaxTransactionJob;
use Kanvas\Souk\Taxes\Models\CustomerTaxExemption;
use Kanvas\Souk\Taxes\Models\OrderTaxSnapshot;
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

    public function testExemptCustomerSeesNoTaxInTheCart(): void
    {
        $profile = $this->user->getAppProfile($this->apps);
        $originalPeopleId = $profile->people_id;
        $people = new CreatePeopleFromUserAction($this->apps, $this->company->defaultBranch, $this->user)->execute();
        $profile->people_id = $people->getId();
        $profile->saveOrFail();

        $exemption = new CustomerTaxExemption();
        $exemption->apps_id = $this->apps->getId();
        $exemption->people_id = $people->getId();
        $exemption->exemption_type = 'resale';
        $exemption->jurisdiction_country = 'US';
        $exemption->is_active = true;
        $exemption->saveOrFail();

        try {
            $this->addToCart()->assertSuccessful();
            $this->applyShippingQuote()->assertSuccessful();

            $this->assertEqualsWithDelta(0.0, (float) $this->cartTax()['total_tax'], 0.001);
        } finally {
            $exemption->forceDelete();
            $profile->people_id = $originalPeopleId;
            $profile->saveOrFail();
        }
    }

    public function testPlacingAnOrderFreezesTheTaxSnapshot(): void
    {
        $this->addToCart()->assertSuccessful();
        $this->applyShippingQuote()->assertSuccessful();
        $cartTax = (float) $this->cartTax()['total_tax'];

        $order = $this->orderFromResponse($this->placeOrderFromCart(address: ['country' => 'United States']));

        $snapshot = $this->snapshotFor($order);
        $this->assertNotNull($snapshot);
        $this->assertSame(TaxStatusEnum::CALCULATED->value, $snapshot->status);
        $this->assertSame('souk', $order->tax_source);
        $this->assertEqualsWithDelta($cartTax, (float) $order->tax_amount, 0.01);
        $this->assertEqualsWithDelta((float) $snapshot->tax_amount, (float) $order->tax_amount, 0.01);
        $this->assertNull($snapshot->checkout_payload['credit_reservation_id'] ?? null);
    }

    public function testPayingTheOrderDispatchesTheTaxCommit(): void
    {
        $this->addToCart()->assertSuccessful();
        $order = $this->orderFromResponse($this->placeOrderFromCart(address: ['country' => 'United States']));

        Queue::fake([CommitTaxTransactionJob::class]);

        $order->markAsPaid($this->user);

        Queue::assertPushed(CommitTaxTransactionJob::class);
        $this->assertSame(TaxStatusEnum::COMMITTING->value, $this->snapshotFor($order)->status);
    }

    public function testNoSnapshotWhenTheTaxEngineIsOff(): void
    {
        $this->apps->set(ConfigurationEnum::TAX_ENGINE_ENABLED->value, 0);
        $this->addToCart()->assertSuccessful();

        $order = $this->orderFromResponse($this->placeOrderFromCart(address: ['country' => 'United States']));

        $this->assertNull($this->snapshotFor($order));
    }

    public function testFreezingTheCartCreatesAnUnattachedSnapshot(): void
    {
        $this->addToCart()->assertSuccessful();
        $this->applyShippingQuote()->assertSuccessful();

        $snapshot = $this->freezeCart();

        $this->assertNotNull($snapshot);
        $this->assertNull($snapshot->order_id);
        $this->assertSame($this->cartIdentifier, $snapshot->cart_session_id);
        $this->assertSame(TaxProviderEnum::MANUAL->value, $snapshot->provider);
        $this->assertSame(GatewayAttemptStatusEnum::PENDING->value, $snapshot->gateway_attempt_status);
        $this->assertCount(1, $snapshot->checkout_payload['lines']);
        $this->assertEqualsWithDelta((float) $this->cartTax()['total_tax'], (float) $snapshot->tax_amount, 0.01);
    }

    public function testFreezingTheCartIsSkippedWhenTheTaxEngineIsOff(): void
    {
        $this->apps->set(ConfigurationEnum::TAX_ENGINE_ENABLED->value, 0);
        $this->addToCart()->assertSuccessful();

        $this->assertNull($this->freezeCart());
    }

    public function testOrderPaidByAnIntentAdoptsTheFrozenSnapshot(): void
    {
        $this->addToCart()->assertSuccessful();
        $this->applyShippingQuote()->assertSuccessful();
        $snapshot = $this->freezeCart();
        $intentId = 'pi_test_' . uniqid();
        $snapshot->update(['payment_intent_id' => $intentId]);

        $order = $this->orderFromResponse($this->placeOrderFromCart(
            metadata: ['paymentIntent' => ['id' => $intentId]],
            address: ['country' => 'United States'],
        ));

        $snapshot->refresh();
        $this->assertSame($intentId, $order->payment_intent_id);
        $this->assertSame($order->getId(), (int) $snapshot->order_id);
        $this->assertSame('souk', $order->tax_source);
        $this->assertEqualsWithDelta((float) $snapshot->tax_amount, (float) $order->tax_amount, 0.01);

        $itemUuid = $order->allItems->first()->uuid;
        $this->assertTrue($snapshot->components->every(fn ($component) => $component->line_id === $itemUuid || $component->line_id === 'shipping'));
    }

    public function testRetryingTheOrderForTheSameIntentReturnsTheSameOrder(): void
    {
        $intentId = 'pi_test_' . uniqid();
        $metadata = ['paymentIntent' => ['id' => $intentId]];

        $this->addToCart()->assertSuccessful();
        $first = $this->orderFromResponse($this->placeOrderFromCart(metadata: $metadata));

        $this->addToCart()->assertSuccessful();
        $second = $this->orderFromResponse($this->placeOrderFromCart(metadata: $metadata));

        $this->assertSame($first->getId(), $second->getId());
    }

    public function testRecoveryBuildsTheOrderWithItemsWhenTheBrowserClosed(): void
    {
        $this->addToCart(2)->assertSuccessful();
        $this->applyShippingQuote()->assertSuccessful();
        $snapshot = $this->freezeCart();
        $intentId = 'pi_test_' . uniqid();
        $snapshot->update(['payment_intent_id' => $intentId]);

        $order = new CompleteCheckoutFromSnapshotAction($snapshot, $intentId, $this->apps)->execute();

        $this->assertCount(1, $order->allItems);
        $this->assertSame(2, (int) $order->allItems->first()->quantity);
        $this->assertNotSame(OrderStatusEnum::ON_HOLD->value, $order->status);
        $this->assertSame($order->getId(), (int) $snapshot->refresh()->order_id);
    }

    public function testRecoveryReusesTheOrderTheFrontEndAlreadyCreated(): void
    {
        $intentId = 'pi_test_' . uniqid();
        $this->addToCart()->assertSuccessful();
        $this->applyShippingQuote()->assertSuccessful();
        $snapshot = $this->freezeCart();
        $snapshot->update(['payment_intent_id' => $intentId]);

        $frontEndOrder = $this->orderFromResponse($this->placeOrderFromCart(metadata: ['paymentIntent' => ['id' => $intentId]]));
        OrderTaxSnapshot::query()->where('id', $snapshot->id)->update(['order_id' => null]);

        $recovered = new CompleteCheckoutFromSnapshotAction($snapshot->refresh(), $intentId, $this->apps)->execute();

        $this->assertSame($frontEndOrder->getId(), $recovered->getId());
    }

    private function freezeCart(): ?OrderTaxSnapshot
    {
        return new FreezeCartTaxAction(
            app('cart')->session($this->cartIdentifier),
            $this->apps,
            $this->company,
            $this->region,
            $this->user
        )->execute();
    }

    private function snapshotFor(Order $order): ?OrderTaxSnapshot
    {
        return OrderTaxSnapshot::query()->where('order_id', $order->getId())->latest('id')->first();
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
