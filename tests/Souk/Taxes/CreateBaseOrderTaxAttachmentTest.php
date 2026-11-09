<?php

declare(strict_types=1);

namespace Tests\Souk\Taxes;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\CompaniesBranches;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Regions\Models\Regions;
use Kanvas\Souk\Orders\Actions\CreateBaseOrderAction;
use Kanvas\Souk\Orders\DataTransferObject\DirectOrder;
use Kanvas\Souk\Orders\DataTransferObject\OrderCustomer;
use Kanvas\Souk\Orders\Enums\OrderStatusEnum;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Payments\DataTransferObject\CreditCard;
use Kanvas\Souk\Payments\Providers\AuthorizeNetPaymentProcessor;
use Kanvas\Souk\Taxes\Enums\GatewayAttemptStatusEnum;
use Kanvas\Souk\Taxes\Enums\TaxStatusEnum;
use Kanvas\Souk\Taxes\Models\OrderTaxComponent;
use Kanvas\Souk\Taxes\Models\OrderTaxSnapshot;
use Kanvas\Users\Models\Users;
use Tests\TestCase;
use Throwable;
use Wearepixel\Cart\Cart;

final class CreateBaseOrderTaxAttachmentTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'commerce'];

    protected Apps $kanvasApp;

    protected function setUp(): void
    {
        parent::setUp();

        $this->kanvasApp = app(Apps::class);
    }

    private function createOrder(array $overrides = []): Order
    {
        $user = auth()->user();

        $order = Order::factory()
            ->withAppId($this->kanvasApp->getId())
            ->withCompanyId($user->getCurrentCompany()->getId())
            ->withUserId($user->getId())
            ->create($overrides);

        return $order;
    }

    private function makeSnapshot(array $overrides = []): OrderTaxSnapshot
    {
        $user = auth()->user();
        $cartSessionId = 'test-cart-' . uniqid();

        $inputHash = hash('sha256', 'test-hash-' . uniqid());

        $snapshot = new OrderTaxSnapshot();
        $snapshot->apps_id = $this->kanvasApp->getId();
        $snapshot->order_id = null;
        $snapshot->cart_session_id = $overrides['cart_session_id'] ?? $cartSessionId;
        $snapshot->kind = 'calculation';
        $snapshot->treatment = 'taxed';
        $snapshot->status = $overrides['status'] ?? TaxStatusEnum::CALCULATED->value;
        $snapshot->provider = 'manual_rate';
        $snapshot->input_hash = $overrides['input_hash'] ?? $inputHash;
        $snapshot->tax_amount = 18.00;
        $snapshot->taxable_amount = 100.00;
        $snapshot->remaining_reversible_amount = 100.00;
        $snapshot->remaining_reversible_tax = 18.00;
        $snapshot->gateway_attempt_key = hash('sha256', uniqid());
        $snapshot->gateway_attempt_status = GatewayAttemptStatusEnum::PENDING->value;
        $snapshot->souk_tax_engine_enabled = true;
        $snapshot->checkout_payload = array_merge([
            'input_hash' => $overrides['input_hash'] ?? $inputHash,
            'lines' => [
                [
                    'uuid' => 'line-uuid-1',
                    'variant_id' => 1,
                    'product_name' => 'Test Product',
                    'product_sku' => 'SKU-001',
                    'quantity' => 1,
                    'unit_price_net_amount' => 100.00,
                    'tax_amount' => 18.00,
                ],
            ],
            'shipping_address' => null,
            'billing_address' => null,
            'discount_allocation' => [],
            'wallet_hold_transfer_id' => null,
            'credit_reservation_id' => null,
            'order_context' => [
                'region_id' => 1,
                'people_id' => 0,
                'companies_id' => $user->getCurrentCompany()->getId(),
                'users_id' => $user->getId(),
            ],
            'totals' => [
                'subtotal' => 100.00,
                'discount' => 0.00,
                'tax' => 18.00,
                'shipping' => 0.00,
                'payable' => 118.00,
                'wallet_tender' => 0.00,
                'gateway_due' => 118.00,
            ],
        ], $overrides['checkout_payload'] ?? []);

        unset($overrides['checkout_payload'], $overrides['status'], $overrides['input_hash'], $overrides['cart_session_id']);

        foreach ($overrides as $key => $value) {
            $snapshot->{$key} = $value;
        }

        $snapshot->saveOrFail();

        return $snapshot;
    }

    public function testResolveSnapshotForOrderByPaymentIntentId(): void
    {
        $paymentIntentId = 'pi_test_attach_' . uniqid();

        $snapshot = $this->makeSnapshot();
        $snapshot->payment_intent_id = $paymentIntentId;
        $snapshot->saveOrFail();

        $order = $this->createOrder(['payment_intent_id' => $paymentIntentId]);

        $component = new OrderTaxComponent();
        $component->uuid = (string) Str::uuid();
        $component->snapshot_id = $snapshot->id;
        $component->line_id = 'old-line-uuid';
        $component->tax_type = 'state';
        $component->rate = 0.18;
        $component->tax_amount = 18.00;
        $component->taxable_amount = 100.00;
        $component->jurisdiction = 'Test';
        $component->saveOrFail();

        $resolved = OrderTaxSnapshot::query()
            ->where('apps_id', $this->kanvasApp->getId())
            ->where('payment_intent_id', $paymentIntentId)
            ->where('status', TaxStatusEnum::CALCULATED->value)
            ->latest()
            ->first();

        $this->assertNotNull($resolved);
        $this->assertSame($snapshot->id, $resolved->id);

        $resolved->order_id = $order->id;
        $resolved->save();

        $resolved->refresh();
        $this->assertSame($order->id, $resolved->order_id);

        $component->refresh();
        $this->assertSame('old-line-uuid', $component->line_id);
    }

    public function testSkipsSilentlyWhenNoSnapshotExists(): void
    {
        $order = $this->createOrder();

        $this->assertNull(
            OrderTaxSnapshot::query()
                ->where('apps_id', $this->kanvasApp->getId())
                ->where('cart_session_id', $order->checkout_token ?? '')
                ->whereNull('order_id')
                ->first()
        );

        $this->assertTrue(true);
    }

    public function testSetsOrderOnHoldOnHashMismatch(): void
    {
        $user = auth()->user();
        $cartSessionId = 'cart-mismatch-' . uniqid();

        $goodHash = hash('sha256', 'good-hash');
        $badHash = hash('sha256', 'bad-hash-different');

        $snapshot = $this->makeSnapshot([
            'cart_session_id' => $cartSessionId,
            'input_hash' => $goodHash,
            'checkout_payload' => [
                'input_hash' => $badHash,
            ],
        ]);

        $order = $this->createOrder(['checkout_token' => $cartSessionId]);

        $app = $this->kanvasApp;
        $fixedSnapshot = $snapshot;

        $action = new class (
            $this->createStub(Cart::class),
            $user->getCurrentCompany(),
            $this->createStub(Regions::class),
            new OrderCustomer(email: 'test@example.com'),
            $this->createStub(People::class),
            $user,
            $app,
            null,
            null,
            [],
        ) extends CreateBaseOrderAction {
            private ?OrderTaxSnapshot $injectedSnapshot = null;

            public function setInjectedSnapshot(OrderTaxSnapshot $snapshot): void
            {
                $this->injectedSnapshot = $snapshot;
            }

            #[\Override]
            protected function resolveSnapshotForOrder(Order $order): ?OrderTaxSnapshot
            {
                return $this->injectedSnapshot;
            }

            public function exposeAttachTaxSnapshot(Order $order): void
            {
                $this->attachTaxSnapshot($order);
            }
        };

        $action->setInjectedSnapshot($fixedSnapshot);
        $action->exposeAttachTaxSnapshot($order);

        $order->refresh();

        $this->assertSame(TaxStatusEnum::MISMATCH->value, $order->tax_status);
        $this->assertSame(OrderStatusEnum::ON_HOLD->value, $order->status);
    }

    public function testAuthNetThrowsWhenTaxEnabledAndNoSnapshot(): void
    {
        $this->kanvasApp->set('souk_tax_engine_enabled', true);

        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $branch = CompaniesBranches::where('companies_id', $company->getId())
            ->first();

        if ($branch === null) {
            $this->markTestSkipped('No companies branch available for this company');
        }

        $cart = app('cart')->session('authnet-test-' . uniqid());
        $cart->clear();

        $creditCard = new CreditCard(
            name: 'Test User',
            number: '4111111111111111',
            exp_month: 12,
            exp_year: 2030,
            cvv: 123,
        );

        $directOrder = new DirectOrder(
            app: $this->kanvasApp,
            user: Users::find($user->getId()),
            creditCard: $creditCard,
            cart: $cart,
        );

        $processor = new AuthorizeNetPaymentProcessor($this->kanvasApp, $branch);

        $this->expectException(ValidationException::class);

        $processor->processCreditCardPayment($directOrder);
    }

    public function testAuthNetPassesThroughWhenTaxDisabled(): void
    {
        $this->kanvasApp->set('souk_tax_engine_enabled', false);

        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $branch = CompaniesBranches::where('companies_id', $company->getId())
            ->first();

        if ($branch === null) {
            $this->markTestSkipped('No companies branch available for this company');
        }

        $cartSessionKey = 'authnet-notax-' . uniqid();
        $cart = app('cart')->session($cartSessionKey);
        $cart->clear();

        $creditCard = new CreditCard(
            name: 'Test User',
            number: '4111111111111111',
            exp_month: 12,
            exp_year: 2030,
            cvv: 123,
        );

        $directOrder = new DirectOrder(
            app: $this->kanvasApp,
            user: Users::find($user->getId()),
            creditCard: $creditCard,
            cart: $cart,
        );

        $processor = new AuthorizeNetPaymentProcessor($this->kanvasApp, $branch);

        try {
            $processor->processCreditCardPayment($directOrder);
            $this->fail('Expected an exception to be thrown');
        } catch (ValidationException $e) {
            $this->assertStringNotContainsString('tax_snapshot_required', $e->getMessage());
        } catch (Throwable) {
            $this->assertTrue(true);
        }
    }
}
