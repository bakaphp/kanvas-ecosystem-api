<?php

declare(strict_types=1);

namespace Tests\Souk\Payments;

use App\GraphQL\Souk\Mutations\Payments\PaymentProcessorMutation;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Payments\Models\PaymentMethods;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Orders\Models\OrderTypes;
use Kanvas\Souk\Payments\DataTransferObject\ThreeDSResult;
use Kanvas\Souk\Payments\Enums\PaymentStatusEnum;
use Kanvas\Souk\Payments\Exceptions\CardVelocityExceededException;
use Kanvas\Souk\Payments\Infrastructure\Processors\Azul\AzulProcessor;
use Kanvas\Souk\Payments\Models\Payments;
use Kanvas\Users\Models\Users;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class ProcessorCardVelocityTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'commerce', 'crm'];

    private Companies $company;
    private Users $customer;
    private People $people;

    protected function setUp(): void
    {
        parent::setUp();

        $this->customer = auth()->user();
        $this->company = $this->customer->getCurrentCompany();
        $this->people = People::factory()
            ->withAppId(app(Apps::class)->getId())
            ->withCompanyId($this->company->getId())
            ->withUserId($this->customer->getId())
            ->create();
    }

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public static function processors(): array
    {
        return [
            'portal' => ['portal'],
            'azul' => ['azul'],
        ];
    }

    #[DataProvider('processors')]
    public function testStartChallengeRejectsTheCardOverTheLimitBeforeBuildingTheProcessor(string $processor): void
    {
        $type = $this->orderType(['max_cards_daily' => 4]);
        $this->payWith(['1111', '2222', '3333'], $type, $processor);
        $payment = $this->pay('4444', $type, $processor);
        $this->forbidProcessor($processor);

        try {
            new PaymentProcessorMutation()->startChallenge(null, ['paymentId' => $payment->getId()]);
            $this->fail('The card velocity limit let the challenge start.');
        } catch (CardVelocityExceededException) {
            $this->assertSame(PaymentStatusEnum::FAILED->value, $payment->refresh()->status);
            $this->assertTrue($payment->paymentLogs()->where('event_type', Payments::CARD_VELOCITY_BLOCKED_EVENT)->exists());
        }
    }

    public function testStartChallengeReachesTheProcessorUnderTheLimit(): void
    {
        $type = $this->orderType(['max_cards_daily' => 4]);
        $this->payWith(['1111', '2222'], $type, 'azul');
        $payment = $this->pay('3333', $type, 'azul');

        $azul = Mockery::mock(AzulProcessor::class);
        $azul->shouldReceive('startChallenge')->once()->andReturn(new ThreeDSResult(
            success: true,
            message: 'Challenge required',
            status: PaymentStatusEnum::PENDING_AUTHORIZATION->value,
        ));
        app()->bind('payment_processor.azul', fn () => $azul);

        $result = new PaymentProcessorMutation()->startChallenge(null, ['paymentId' => $payment->getId()]);

        $this->assertTrue($result['success']);
        $this->assertFalse($payment->paymentLogs()->where('event_type', Payments::CARD_VELOCITY_BLOCKED_EVENT)->exists());
    }

    public function testProcessPaymentRejectsTheCardOverTheLimitBeforeBuildingTheProcessor(): void
    {
        $type = $this->orderType(['max_cards_daily' => 2]);
        $this->payWith(['1111'], $type, 'azul');
        $payment = $this->pay('2222', $type, 'azul');
        $this->forbidProcessor('azul');

        $this->expectException(CardVelocityExceededException::class);

        try {
            new PaymentProcessorMutation()->processPayment(null, ['paymentId' => $payment->getId()]);
        } finally {
            $this->assertSame(PaymentStatusEnum::FAILED->value, $payment->refresh()->status);
        }
    }

    private function forbidProcessor(string $processor): void
    {
        app()->bind(
            "payment_processor.{$processor}",
            fn () => $this->fail("The {$processor} processor was built for a card over the limit.")
        );
    }

    private function payWith(array $lastFours, OrderTypes $type, string $processor): void
    {
        foreach ($lastFours as $lastFour) {
            $this->pay($lastFour, $type, $processor);
        }
    }

    private function pay(string $lastFour, OrderTypes $type, string $processor): Payments
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
        $payment->payment_methods_id = $this->paymentMethod($processor)->getId();
        $payment->payable_id = $order->getId();
        $payment->payable_type = Order::class;
        $payment->payment_date = now()->toDateString();
        $payment->payment_method = 'card';
        $payment->payment_method_brand = 'visa';
        $payment->payment_method_last_four = $lastFour;
        $payment->processor = $processor;
        $payment->concept = 'Payment ' . $order->reference;
        $payment->amount = 100.0;
        $payment->currency = 'DOP';
        $payment->status = PaymentStatusEnum::PENDING->value;
        $payment->is_deleted = false;
        $payment->saveOrFail();

        return $payment;
    }

    private function paymentMethod(string $processor): PaymentMethods
    {
        $paymentMethod = new PaymentMethods();
        $paymentMethod->apps_id = app(Apps::class)->getId();
        $paymentMethod->companies_id = $this->company->getId();
        $paymentMethod->users_id = $this->customer->getId();
        $paymentMethod->payment_methods_brand = 'visa';
        $paymentMethod->payment_ending_numbers = '0000';
        $paymentMethod->expiration_date = '12/30';
        $paymentMethod->stripe_card_id = 'mock-pid-123';
        $paymentMethod->processor = $processor;
        $paymentMethod->metadata = [];
        $paymentMethod->saveOrFail();

        return $paymentMethod;
    }

    private function orderType(array $cardVelocity): OrderTypes
    {
        $type = new OrderTypes();
        $type->apps_id = app(Apps::class)->getId();
        $type->companies_id = $this->company->getId();
        $type->name = 'processor_velocity_' . uniqid();
        $type->config = ['card_velocity' => $cardVelocity];
        $type->saveOrFail();

        return $type;
    }
}
