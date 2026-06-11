<?php

declare(strict_types=1);

namespace Tests\GraphQL\Connectors;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Stripe\Services\StripeTokenizationService;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Payments\Models\PaymentMethods;
use Kanvas\Regions\Models\Regions;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Payments\Enums\PaymentStatusEnum;
use Kanvas\Souk\Payments\Enums\RefundStatusEnum;
use Kanvas\Souk\Payments\Infrastructure\Processors\Stripe\StripeProcessor;
use Kanvas\Souk\Payments\Models\PaymentRefund;
use Kanvas\Souk\Payments\Models\Payments;
use Stripe\Customer;
use Stripe\PaymentIntent;
use Stripe\PaymentMethod as StripePaymentMethod;
use Stripe\Refund;
use Tests\Connectors\Stripe\Fakes\FakeStripeClient;
use Tests\TestCase;

class StripePaymentFlowTest extends TestCase
{
    use DatabaseTransactions;

    /** PaymentMethods live on `ecosystem`; Orders/Payments/PaymentRefund on `commerce`. */
    protected $connectionsToTransact = ['ecosystem', 'commerce'];

    private Apps $currentApp;
    private Companies $currentCompany;
    private FakeStripeClient $fakeStripe;
    private int $regionId;
    private int $peopleId;

    public function setUp(): void
    {
        parent::setUp();

        $this->currentApp = app(Apps::class);
        $this->currentCompany = static::$cachedUser->getCurrentCompany();
        $this->regionId = Regions::getDefault($this->currentCompany, $this->currentApp)->id;
        $this->peopleId = People::first()->id;

        $this->fakeStripe = new FakeStripeClient();

        $this->app->bind(
            'payment.stripe',
            fn ($app, array $params = []) => new StripeTokenizationService(
                $params['app'] ?? $app->make(Apps::class),
                $params['company'] ?? $this->currentCompany,
                $this->fakeStripe,
            )
        );

        $this->app->bind(
            'payment_processor.stripe',
            fn ($app, array $params = []) => new StripeProcessor(
                $params['app'] ?? $app->make(Apps::class),
                $params['company'] ?? $this->currentCompany,
                $this->fakeStripe,
            )
        );
    }

    public function testCreatePaymentMethodTokenize(): void
    {
        $pmId = 'pm_flow_' . uniqid();
        $customerId = 'cus_flow_' . uniqid();

        $this->fakeStripe->getCustomers()->queueResponse('create', $this->fakeCustomer($customerId));
        $this->fakeStripe->getPaymentMethods()->queueResponse('attach', $this->fakeStripePaymentMethod($pmId));
        $this->fakeStripe->getPaymentMethods()->queueResponse('retrieve', $this->fakeStripePaymentMethod($pmId));

        $response = $this->graphQL('
            mutation($input: PaymentMethodInput!) {
                createPaymentMethod(input: $input) {
                    id
                    processor
                }
            }
        ', [
            'input' => [
                'processor' => 'stripe',
                'stripe_payment_method_id' => $pmId,
                'expiration_date' => '2030-12',
            ],
        ], [], [
            'X-Kanvas-Location' => $this->currentCompany->branch->uuid,
        ]);

        $response->assertSuccessful();
        $id = (int) $response->json('data.createPaymentMethod.id');
        $this->assertSame('stripe', $response->json('data.createPaymentMethod.processor'));

        $row = PaymentMethods::find($id);
        $this->assertNotNull($row);
        $this->assertSame('stripe', $row->processor);
        $this->assertSame($pmId, $row->stripe_card_id);
        $this->assertSame($customerId, $row->metadata['stripe_customer_id']);
        $this->assertSame($pmId, $row->metadata['stripe_payment_method_id']);
    }

    public function testAddPaymentToOrderHappyPath(): void
    {
        $order = $this->buildOrder();
        $paymentMethod = $this->createStripePaymentMethod();

        $payment = $this->addPaymentToOrder($order, $paymentMethod);

        $this->fakeStripe->getPaymentIntents()->queueResponse('create', $this->fakeIntent('pi_flow_ok', 'succeeded', [
            'latest_charge' => 'ch_flow_ok',
        ]));

        $response = $this->processPayment($payment);

        $response->assertSuccessful();
        $this->assertSame('success', $response->json('data.processPayment.status'));

        $payment->refresh();
        $this->assertSame(PaymentStatusEnum::PAID->value, $payment->status);
        $this->assertSame('pi_flow_ok', $payment->payment_intent_id);
        $this->assertSame('ch_flow_ok', $payment->getMetadata('stripe_charge_id'));

        $order->refresh();
        $this->assertTrue($order->isPaid());

        $this->assertCount(1, $this->fakeStripe->getPaymentIntents()->getCalls('create'));
    }

    public function testAddPaymentToOrderRequires3DS(): void
    {
        $order = $this->buildOrder();
        $paymentMethod = $this->createStripePaymentMethod();
        $payment = $this->addPaymentToOrder($order, $paymentMethod);

        $this->fakeStripe->getPaymentIntents()->queueResponse('create', $this->fakeIntent('pi_flow_3ds', 'requires_action', [
            'client_secret' => 'cs_fake_flow_3ds',
        ]));

        $response = $this->processPayment($payment);

        $response->assertSuccessful();
        // processPayment surfaces the raw PaymentIntent, which carries client_secret.
        $this->assertSame('cs_fake_flow_3ds', $response->json('data.processPayment.data.client_secret'));

        $payment->refresh();
        $this->assertSame(PaymentStatusEnum::PENDING_AUTHORIZATION->value, $payment->status);
        $this->assertSame('cs_fake_flow_3ds', $payment->getMetadata('stripe_client_secret'));
    }

    public function testStartPaymentChallengeReturnsClientSecret(): void
    {
        $order = $this->buildOrder();
        $paymentMethod = $this->createStripePaymentMethod();
        $payment = $this->createPaymentInState(
            $order,
            $paymentMethod,
            PaymentStatusEnum::PENDING_AUTHORIZATION->value,
            'pi_flow_start',
        );

        $this->fakeStripe->getPaymentIntents()->queueResponse('retrieve', $this->fakeIntent('pi_flow_start', 'requires_action', [
            'client_secret' => 'cs_fake_flow_start',
        ]));

        $response = $this->graphQL('
            mutation($paymentId: ID!) {
                startPaymentChallenge(paymentId: $paymentId) {
                    success
                    status
                    data
                }
            }
        ', [
            'paymentId' => (string) $payment->id,
        ], [], [
            'X-Kanvas-Location' => $this->currentCompany->branch->uuid,
        ]);

        $response->assertSuccessful();
        $result = $response->json('data.startPaymentChallenge');

        $this->assertTrue($result['success']);
        $this->assertSame(PaymentStatusEnum::PENDING_AUTHORIZATION->value, $result['status']);
        $this->assertSame('cs_fake_flow_start', $result['data']['client_secret']);
    }

    public function testFinalizePaymentChallengePaid(): void
    {
        $order = $this->buildOrder();
        $paymentMethod = $this->createStripePaymentMethod();
        $payment = $this->createPaymentInState(
            $order,
            $paymentMethod,
            PaymentStatusEnum::PENDING_AUTHORIZATION->value,
            'pi_flow_fin',
            ['stripe_client_secret' => 'cs_fake_flow_fin'],
        );

        $this->fakeStripe->getPaymentIntents()->queueResponse('retrieve', $this->fakeIntent('pi_flow_fin', 'succeeded', [
            'latest_charge' => 'ch_flow_fin',
        ]));

        $response = $this->graphQL('
            mutation($paymentId: ID!) {
                finalizePaymentChallenge(paymentId: $paymentId) {
                    success
                    status
                }
            }
        ', [
            'paymentId' => (string) $payment->id,
        ], [], [
            'X-Kanvas-Location' => $this->currentCompany->branch->uuid,
        ]);

        $response->assertSuccessful();
        $result = $response->json('data.finalizePaymentChallenge');

        $this->assertTrue($result['success']);
        $this->assertSame(PaymentStatusEnum::PAID->value, $result['status']);

        $payment->refresh();
        $this->assertSame(PaymentStatusEnum::PAID->value, $payment->status);
        // Transient client_secret cleared on terminal state.
        $this->assertNull($payment->getMetadata('stripe_client_secret'));
    }

    public function testRefundPaymentFull(): void
    {
        $order = $this->buildOrder();
        $paymentMethod = $this->createStripePaymentMethod();
        $payment = $this->createPaymentInState(
            $order,
            $paymentMethod,
            PaymentStatusEnum::PAID->value,
            'pi_flow_ref',
            ['stripe_charge_id' => 'ch_flow_ref'],
        );

        $this->fakeStripe->getRefunds()->queueResponse('create', Refund::constructFrom([
            'id' => 're_flow_full',
            'object' => 'refund',
            'status' => 'succeeded',
        ]));

        $response = $this->graphQL('
            mutation($paymentId: ID!) {
                refundPayment(paymentId: $paymentId) {
                    status
                    message
                }
            }
        ', [
            'paymentId' => (string) $payment->id,
        ], [], [
            'X-Kanvas-Location' => $this->currentCompany->branch->uuid,
        ]);

        $response->assertSuccessful();
        $this->assertSame('success', $response->json('data.refundPayment.status'));

        $refund = PaymentRefund::where('payments_id', $payment->getId())->first();
        $this->assertNotNull($refund);
        $this->assertSame(RefundStatusEnum::COMPLETED->value, $refund->status);

        $payment->refresh();
        $this->assertSame(PaymentStatusEnum::REVERSED->value, $payment->status);
    }

    public function testRefundPaymentPartial(): void
    {
        $order = $this->buildOrder();
        $paymentMethod = $this->createStripePaymentMethod();
        $payment = $this->createPaymentInState(
            $order,
            $paymentMethod,
            PaymentStatusEnum::PAID->value,
            'pi_flow_refp',
            ['stripe_charge_id' => 'ch_flow_refp'],
        );

        $this->fakeStripe->getRefunds()->queueResponse('create', Refund::constructFrom([
            'id' => 're_flow_part',
            'object' => 'refund',
            'status' => 'succeeded',
        ]));

        $response = $this->graphQL('
            mutation($paymentId: ID!, $amount: Float) {
                refundPayment(paymentId: $paymentId, amount: $amount) {
                    status
                }
            }
        ', [
            'paymentId' => (string) $payment->id,
            'amount' => 40.00,
        ], [], [
            'X-Kanvas-Location' => $this->currentCompany->branch->uuid,
        ]);

        $response->assertSuccessful();
        $this->assertSame('success', $response->json('data.refundPayment.status'));

        $refund = PaymentRefund::where('payments_id', $payment->getId())->first();
        $this->assertEquals(40.00, (float) $refund->amount);

        $payment->refresh();
        $this->assertSame(PaymentStatusEnum::PAID->value, $payment->status);
    }

    private function processPayment(Payments $payment): TestResponse
    {
        return $this->graphQL('
            mutation($paymentId: ID!) {
                processPayment(paymentId: $paymentId) {
                    status
                    message
                    data
                }
            }
        ', [
            'paymentId' => (string) $payment->id,
        ], [], [
            'X-Kanvas-Location' => $this->currentCompany->branch->uuid,
        ]);
    }

    private function addPaymentToOrder(Order $order, PaymentMethods $paymentMethod): Payments
    {
        $response = $this->graphQL('
            mutation($orderID: ID!, $input: PaymentInput!) {
                addPaymentToOrder(orderID: $orderID, input: $input) {
                    status
                    payment { id }
                }
            }
        ', [
            'orderID' => (string) $order->id,
            'input' => [
                'payment_methods_id' => (string) $paymentMethod->id,
            ],
        ], [], [
            'X-Kanvas-Location' => $this->currentCompany->branch->uuid,
        ]);

        $response->assertSuccessful();
        $this->assertSame('success', $response->json('data.addPaymentToOrder.status'));

        return Payments::findOrFail((int) $response->json('data.addPaymentToOrder.payment.id'));
    }

    private function buildOrder(): Order
    {
        $order = new Order();
        $order->apps_id = $this->currentApp->getId();
        $order->companies_id = $this->currentCompany->getId();
        $order->users_id = static::$cachedUser->getId();
        $order->region_id = $this->regionId;
        $order->people_id = $this->peopleId;
        $order->order_number = random_int(10000, 999999);
        $order->total_gross_amount = 100.00;
        $order->total_net_amount = 100.00;
        $order->status = 'draft';
        $order->fulfillment_status = 'pending';
        $order->currency = 'USD';
        $order->is_deleted = false;
        $order->saveOrFail();

        return $order;
    }

    private function createStripePaymentMethod(): PaymentMethods
    {
        return PaymentMethods::create([
            'apps_id' => $this->currentApp->getId(),
            'companies_id' => $this->currentCompany->getId(),
            'users_id' => static::$cachedUser->getId(),
            'payment_ending_numbers' => '4242',
            'payment_methods_brand' => 'visa',
            'expiration_date' => '2030-12',
            'processor' => 'stripe',
            'stripe_card_id' => 'pm_saved_' . uniqid(),
            'metadata' => [
                'stripe_customer_id' => 'cus_saved_' . uniqid(),
                'stripe_payment_method_id' => 'pm_saved_meta_' . uniqid(),
            ],
        ]);
    }

    private function createPaymentInState(
        Order $order,
        PaymentMethods $paymentMethod,
        string $status,
        ?string $intentId,
        array $metadataData = [],
    ): Payments {
        $payment = new Payments();
        $payment->apps_id = $this->currentApp->getId();
        $payment->companies_id = $this->currentCompany->getId();
        $payment->users_id = static::$cachedUser->getId();
        $payment->payable_id = $order->id;
        $payment->payable_type = get_class($order);
        $payment->payment_methods_id = $paymentMethod->id;
        $payment->payment_date = now()->toDateString();
        $payment->concept = 'Stripe flow test';
        $payment->amount = $order->getTotalAmount();
        $payment->currency = $order->currency ?? 'USD';
        $payment->status = $status;
        $payment->processor = 'stripe';
        $payment->payment_intent_id = $intentId;
        $payment->idempotency_key = Str::uuid()->toString();
        $payment->metadata = $metadataData ? ['data' => $metadataData] : [];
        $payment->saveOrFail();

        return $payment;
    }

    private function fakeIntent(string $id, string $status, array $extra = []): PaymentIntent
    {
        return PaymentIntent::constructFrom(array_merge([
            'id' => $id,
            'object' => 'payment_intent',
            'status' => $status,
        ], $extra));
    }

    private function fakeStripePaymentMethod(string $pmId): StripePaymentMethod
    {
        return StripePaymentMethod::constructFrom([
            'id' => $pmId,
            'object' => 'payment_method',
            'type' => 'card',
            'card' => [
                'last4' => '4242',
                'brand' => 'visa',
                'exp_year' => 2030,
                'exp_month' => 12,
            ],
        ]);
    }

    private function fakeCustomer(string $customerId): Customer
    {
        return Customer::constructFrom([
            'id' => $customerId,
            'object' => 'customer',
            'email' => 'flow@example.com',
        ]);
    }
}
