<?php

declare(strict_types=1);

namespace Tests\Connectors\Portal;

use App\GraphQL\Souk\Mutations\Payments\PaymentProcessorMutation;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\EchoPay\Services\EchoPayService;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Payments\Models\PaymentMethods;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Orders\Models\OrderTypes;
use Kanvas\Souk\Payments\Enums\PaymentStatusEnum;
use Kanvas\Souk\Payments\Exceptions\CardVelocityExceededException;
use Kanvas\Souk\Payments\Infrastructure\Processors\Portal\PortalProcessor;
use Kanvas\Souk\Payments\Models\Payments;
use Kanvas\Users\Models\Users;
use Mockery;
use Tests\TestCase;

final class PortalCardVelocityTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'commerce', 'crm'];

    private Apps $currentApp;
    private Companies $company;
    private Users $customer;
    private People $people;
    private PaymentMethods $paymentMethod;
    private EchoPayService $service;
    private PortalProcessor $processor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->currentApp = Mockery::mock(app(Apps::class))->makePartial();
        $this->currentApp->shouldReceive('get')->andReturnUsing(fn (string $key): mixed => [
            'ECHO_PAY_MERCHANT_ID' => 'merchant-default',
            'ECHO_PAY_MERCHANT_KEY' => 'key-default',
            'ECHO_PAY_MERCHANT_SECRET' => 'secret-default',
        ][$key] ?? null);
        $this->customer = auth()->user();
        $this->company = $this->customer->getCurrentCompany();
        $this->people = People::factory()
            ->withAppId(app(Apps::class)->getId())
            ->withCompanyId($this->company->getId())
            ->withUserId($this->customer->getId())
            ->create();
        $this->paymentMethod = $this->makePaymentMethod();
        $this->service = Mockery::mock(EchoPayService::class);
        $this->processor = new PortalProcessor(
            app: $this->currentApp,
            company: $this->company,
            service: $this->service,
        );
    }

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function testStartChallengeProceedsUnderTheLimit(): void
    {
        $type = $this->orderType(['max_cards_daily' => 4]);
        $this->payWith(['1111', '2222'], $type);
        $payment = $this->pay('3333', $type);
        $this->expectSetupPayer();

        $result = $this->processor->startChallenge($payment, $payment->payable);

        $this->assertTrue($result->success);
        $this->assertSame(PaymentStatusEnum::WAITING_DEVICE_DATA->value, $payment->refresh()->status);
    }

    public function testStartChallengeRejectsTheCardOverTheLimitWithoutCallingTheGateway(): void
    {
        $type = $this->orderType(['max_cards_daily' => 4]);
        $this->payWith(['1111', '2222', '3333'], $type);
        $payment = $this->pay('4444', $type);
        $paymentsBefore = Payments::query()->count();
        $this->service->shouldNotReceive('setupPayer');

        try {
            $this->processor->startChallenge($payment, $payment->payable);
            $this->fail('The card velocity limit let the challenge start.');
        } catch (CardVelocityExceededException $e) {
            $this->assertSame(PaymentStatusEnum::FAILED->value, $payment->refresh()->status);
            $this->assertSame($paymentsBefore, Payments::query()->count());
        }
    }

    private function expectSetupPayer(): void
    {
        $this->service->shouldReceive('setupPayer')->once()->andReturn([
            'consumerAuthenticationInformation' => [
                'accessToken' => 'tok-1',
                'deviceDataCollectionUrl' => 'https://mock/3ds',
                'referenceId' => 'ref-1',
            ],
            'id' => 'setup-1',
        ]);
    }

    private function payWith(array $lastFours, OrderTypes $type): void
    {
        foreach ($lastFours as $lastFour) {
            $this->pay($lastFour, $type);
        }
    }

    private function pay(string $lastFour, OrderTypes $type): Payments
    {
        $order = Order::factory()
            ->withAppId(app(Apps::class)->getId())
            ->withCompanyId($this->company->getId())
            ->withUserId($this->customer->getId())
            ->withPeopleId($this->people->getId())
            ->create(['order_types_id' => $type->getId()]);

        $payment = new Payments();
        $payment->apps_id = app(Apps::class)->getId();
        $payment->companies_id = $this->company->getId();
        $payment->users_id = $this->customer->getId();
        $payment->payment_methods_id = $this->paymentMethod->getId();
        $payment->payable_id = $order->getId();
        $payment->payable_type = Order::class;
        $payment->payment_date = now()->toDateString();
        $payment->payment_method = 'card';
        $payment->payment_method_brand = 'visa';
        $payment->payment_method_last_four = $lastFour;
        $payment->processor = 'portal';
        $payment->concept = 'Payment ' . $order->reference;
        $payment->amount = 100.0;
        $payment->currency = 'DOP';
        $payment->status = PaymentStatusEnum::PENDING->value;
        $payment->is_deleted = false;
        $payment->saveOrFail();

        return $payment;
    }

    private function makePaymentMethod(): PaymentMethods
    {
        $paymentMethod = new PaymentMethods();
        $paymentMethod->apps_id = app(Apps::class)->getId();
        $paymentMethod->companies_id = $this->company->getId();
        $paymentMethod->users_id = $this->customer->getId();
        $paymentMethod->payment_methods_brand = 'visa';
        $paymentMethod->payment_ending_numbers = '0000';
        $paymentMethod->expiration_date = '12/30';
        $paymentMethod->stripe_card_id = 'mock-pid-123';
        $paymentMethod->processor = 'portal';
        $paymentMethod->metadata = [];
        $paymentMethod->saveOrFail();

        return $paymentMethod;
    }

    private function orderType(?array $cardVelocity = null): OrderTypes
    {
        $type = new OrderTypes();
        $type->apps_id = app(Apps::class)->getId();
        $type->companies_id = $this->company->getId();
        $type->name = 'portal_velocity_' . uniqid();
        $type->config = $cardVelocity ? ['card_velocity' => $cardVelocity] : null;
        $type->saveOrFail();

        return $type;
    }
}
