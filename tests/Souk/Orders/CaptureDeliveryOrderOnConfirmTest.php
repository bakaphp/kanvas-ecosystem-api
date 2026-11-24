<?php

declare(strict_types=1);

namespace Tests\Souk\Orders;

use ArrayObject;
use DomainException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Souk\Orders\Actions\SetupDeliveryOrderTypeAction;
use Kanvas\Souk\Orders\Actions\TransitionOrderStateAction;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Orders\Models\OrderStatus;
use Kanvas\Souk\Orders\Models\OrderTypes;
use Kanvas\Souk\Payments\Contracts\PaymentProcessorInterface;
use Kanvas\Souk\Payments\DataTransferObject\AuthorizeResult;
use Kanvas\Souk\Payments\DataTransferObject\CaptureResult;
use Kanvas\Souk\Payments\DataTransferObject\RefundResult;
use Kanvas\Souk\Payments\DataTransferObject\VerifyResult;
use Kanvas\Souk\Payments\DataTransferObject\VoidResult;
use Kanvas\Souk\Payments\Enums\PaymentStatusEnum;
use Kanvas\Souk\Payments\Models\Payments;
use RuntimeException;
use Tests\Souk\Concerns\CreatesDeliveryOrder;
use Tests\TestCase;

final class CaptureDeliveryOrderOnConfirmTest extends TestCase
{
    use CreatesDeliveryOrder;
    use DatabaseTransactions;

    private const string PROCESSOR = 'capture-on-confirm-test';

    protected $connectionsToTransact = [null, 'workflow', 'commerce', 'crm', 'ecosystem'];

    private Companies $company;
    private ArrayObject $captureCalls;
    private array $captureOutcomes = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Companies::factory()->create();
        $this->setUpDeliveryOrderStatuses();
        $this->captureCalls = new ArrayObject();
        $this->captureOutcomes = [];
        $this->app->bind('payment_processor.' . self::PROCESSOR, fn () => $this->fakeProcessor());
    }

    public function testASuccessfulCaptureConfirmsTheOrderAndMarksItPaid(): void
    {
        $order = $this->deliveryOrder($this->company, 'verifying');
        $payment = $this->payment($order, PaymentStatusEnum::AUTHORIZED, 80.0);

        $result = $this->confirm($order);

        $this->assertSame('success', $result['status']);
        $this->assertSame('confirmed', $this->statusSlugOf($order));
        $this->assertSame('paid', $order->payment_status);
        $this->assertSame(PaymentStatusEnum::PAID->value, $payment->refresh()->status);
        $this->assertSame([80.0], $this->captureCalls->getArrayCopy());
    }

    public function testADeclinedCaptureKeepsTheOrderInVerifyingAndThePaymentAuthorized(): void
    {
        $order = $this->deliveryOrder($this->company, 'verifying');
        $payment = $this->payment($order, PaymentStatusEnum::AUTHORIZED, 80.0);
        $this->captureOutcomes = ['declined by issuer'];

        $result = $this->confirm($order);

        $this->assertSame('error', $result['status']);
        $this->assertStringContainsString('declined by issuer', $result['message']);
        $this->assertSame('verifying', $this->statusSlugOf($order));
        $this->assertSame(PaymentStatusEnum::AUTHORIZED->value, $payment->refresh()->status);
    }

    public function testASuccessThatLeavesThePaymentUnpaidRefusesTheTransition(): void
    {
        $order = $this->deliveryOrder($this->company, 'verifying');
        $payment = $this->payment($order, PaymentStatusEnum::AUTHORIZED, 80.0);
        $this->captureOutcomes = [false];

        $result = $this->confirm($order);

        $this->assertSame('error', $result['status']);
        $this->assertStringContainsString('payment is not paid', $result['message']);
        $this->assertSame('verifying', $this->statusSlugOf($order));
        $this->assertSame(PaymentStatusEnum::AUTHORIZED->value, $payment->refresh()->status);
    }

    public function testAProcessorExceptionKeepsTheOrderInVerifyingAndThePaymentAuthorized(): void
    {
        $order = $this->deliveryOrder($this->company, 'verifying');
        $payment = $this->payment($order, PaymentStatusEnum::AUTHORIZED, 80.0);
        $this->captureOutcomes = [new DomainException('gateway timeout')];

        $result = $this->confirm($order);

        $this->assertSame('error', $result['status']);
        $this->assertStringContainsString('gateway timeout', $result['message']);
        $this->assertSame('verifying', $this->statusSlugOf($order));
        $this->assertSame(PaymentStatusEnum::AUTHORIZED->value, $payment->refresh()->status);
    }

    public function testAMissingProcessorRefusesTheTransition(): void
    {
        $order = $this->deliveryOrder($this->company, 'verifying');
        $payment = $this->payment($order, PaymentStatusEnum::AUTHORIZED, 80.0, 'not-registered');

        $result = $this->confirm($order);

        $this->assertSame('error', $result['status']);
        $this->assertStringContainsString('No payment processor registered', $result['message']);
        $this->assertSame('verifying', $this->statusSlugOf($order));
        $this->assertSame(PaymentStatusEnum::AUTHORIZED->value, $payment->refresh()->status);
    }

    public function testWithoutQuietModeTheRefusalIsThrownWithTheProcessorMessage(): void
    {
        $order = $this->deliveryOrder($this->company, 'verifying');
        $this->payment($order, PaymentStatusEnum::AUTHORIZED, 80.0);
        $this->captureOutcomes = ['declined by issuer'];

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('declined by issuer');

        new TransitionOrderStateAction($order, auth()->user(), $this->statusOf('confirmed'))->execute();
    }

    public function testAnAlreadyPaidOrderConfirmsWithoutCallingTheProcessor(): void
    {
        $order = $this->deliveryOrder($this->company, 'verifying');
        $this->payment($order, PaymentStatusEnum::PAID, 80.0);

        $result = $this->confirm($order);

        $this->assertSame('success', $result['status']);
        $this->assertSame('confirmed', $this->statusSlugOf($order));
        $this->assertCount(0, $this->captureCalls);
    }

    public function testCapturingThePaymentAloneLeavesTheOrderInVerifyingAndALaterConfirmDoesNotCaptureAgain(): void
    {
        $order = $this->deliveryOrder($this->company, 'verifying');
        $payment = $this->payment($order, PaymentStatusEnum::AUTHORIZED, 80.0);
        $this->fakeProcessor()->capture($payment, $order);

        $this->assertSame('verifying', $this->statusSlugOf($order));
        $this->assertSame('paid', $order->payment_status);

        $result = $this->confirm($order);

        $this->assertSame('success', $result['status']);
        $this->assertSame('confirmed', $this->statusSlugOf($order));
        $this->assertCount(1, $this->captureCalls);
    }

    public function testAnOrderWithoutAnyPaymentCannotBeConfirmed(): void
    {
        $order = $this->deliveryOrder($this->company, 'verifying');

        $result = $this->confirm($order);

        $this->assertSame('error', $result['status']);
        $this->assertStringContainsString('no authorized payment', $result['message']);
        $this->assertSame('verifying', $this->statusSlugOf($order));
    }

    public function testAFailedPaymentOnlyOrderCannotBeConfirmed(): void
    {
        $order = $this->deliveryOrder($this->company, 'verifying');
        $this->payment($order, PaymentStatusEnum::FAILED, 80.0);

        $result = $this->confirm($order);

        $this->assertSame('error', $result['status']);
        $this->assertSame('verifying', $this->statusSlugOf($order));
        $this->assertCount(0, $this->captureCalls);
    }

    public function testWhenTheSecondPaymentFailsTheFirstStaysCapturedAndTheTransitionIsRefused(): void
    {
        $order = $this->deliveryOrder($this->company, 'verifying');
        $first = $this->payment($order, PaymentStatusEnum::AUTHORIZED, 50.0);
        $second = $this->payment($order, PaymentStatusEnum::AUTHORIZED, 30.0);
        $this->captureOutcomes = [true, 'insufficient funds'];

        $result = $this->confirm($order);

        $this->assertSame('error', $result['status']);
        $this->assertStringContainsString("Payment {$second->getId()} could not be captured: insufficient funds", $result['message']);
        $this->assertStringContainsString("Already captured and not reverted: payments {$first->getId()}", $result['message']);
        $this->assertSame('verifying', $this->statusSlugOf($order));
        $this->assertSame(PaymentStatusEnum::PAID->value, $first->refresh()->status);
        $this->assertSame(PaymentStatusEnum::AUTHORIZED->value, $second->refresh()->status);
        $this->assertEqualsCanonicalizing([50.0, 30.0], $this->captureCalls->getArrayCopy());
    }

    public function testAnOrderTypeWithoutTheConfigTransitionsWithoutCapturing(): void
    {
        $order = $this->deliveryOrder($this->company, 'verifying');
        $this->payment($order, PaymentStatusEnum::AUTHORIZED, 80.0);
        OrderTypes::find($order->order_types_id)->update(['config' => []]);

        $result = $this->confirm($order->refresh());

        $this->assertSame('success', $result['status']);
        $this->assertSame('confirmed', $this->statusSlugOf($order));
        $this->assertCount(0, $this->captureCalls);
    }

    public function testSetupWritesTheCaptureConfigAndKeepsTheRestOfTheConfig(): void
    {
        $type = OrderTypes::find($this->statusOf('verifying')->order_types_id);
        $type->update(['config' => ['card_velocity' => ['per_day' => 3], 'capture_on_status' => ['other']]]);

        new SetupDeliveryOrderTypeAction(app(Apps::class))->execute();

        $config = $type->refresh()->config;
        $this->assertSame(['other', 'confirmed'], $config['capture_on_status']);
        $this->assertSame(['per_day' => 3], $config['card_velocity']);
        $this->assertTrue($type->capturesOnStatus('confirmed'));
        $this->assertFalse($type->capturesOnStatus('verifying'));
    }

    public function testSetupRenamesAnExistingPaidStatusInPlace(): void
    {
        $confirmed = $this->statusOf('confirmed');
        $confirmed->update(['slug' => 'paid', 'name' => 'Paid']);
        $order = $this->deliveryOrder($this->company, 'verifying', ['order_status_id' => $confirmed->getId()]);

        $result = new SetupDeliveryOrderTypeAction(app(Apps::class))->execute();

        $renamed = collect($result['statuses'])->firstWhere('slug', 'confirmed');
        $this->assertSame($confirmed->getId(), $renamed->getId());
        $this->assertSame('Order confirmed', $renamed->refresh()->name);
        $this->assertFalse(
            OrderStatus::query()->where('order_types_id', $confirmed->order_types_id)->where('slug', 'paid')->exists()
        );
        $this->assertSame('confirmed', $this->statusSlugOf($order));
    }

    private function confirm(Order $order): array
    {
        return new TransitionOrderStateAction($order, auth()->user(), $this->statusOf('confirmed'))->execute(true);
    }

    private function statusOf(string $slug): OrderStatus
    {
        return OrderStatus::find($this->deliveryOrderStatuses[$slug]->getId());
    }

    private function payment(
        Order $order,
        PaymentStatusEnum $status,
        float $amount,
        string $processor = self::PROCESSOR
    ): Payments {
        $payment = new Payments();
        $payment->apps_id = app(Apps::class)->getId();
        $payment->companies_id = $this->company->getId();
        $payment->users_id = auth()->user()->getId();
        $payment->payment_methods_id = 0;
        $payment->payable_id = $order->getId();
        $payment->payable_type = $order->getMorphClass();
        $payment->concept = 'Capture on confirm test';
        $payment->payment_date = now()->toDateString();
        $payment->amount = $amount;
        $payment->currency = 'USD';
        $payment->status = $status->value;
        $payment->processor = $processor;
        $payment->payment_intent_id = 'pi_' . Str::random(8);
        $payment->saveOrFail();

        return $payment;
    }

    private function fakeProcessor(): PaymentProcessorInterface
    {
        return new class ($this->captureCalls, $this->captureOutcomes) implements PaymentProcessorInterface {
            public function __construct(private ArrayObject $calls, private array $outcomes)
            {
            }

            public function name(): string
            {
                return 'capture-on-confirm-test';
            }

            public function authorize(Payments $payment, Order $order, array $context = []): AuthorizeResult
            {
                throw new DomainException('Not used.');
            }

            public function capture(Payments $payment, Order $order, ?float $amount = null, array $context = []): CaptureResult
            {
                $this->calls[] = $amount;
                $outcome = $this->outcomes[count($this->calls) - 1] ?? true;

                if ($outcome instanceof DomainException) {
                    throw $outcome;
                }

                if (is_string($outcome)) {
                    return new CaptureResult(success: false, message: $outcome, transactionId: '');
                }

                if ($outcome === true) {
                    $payment->markAsPaid();
                }

                return new CaptureResult(success: true, message: 'captured', transactionId: $payment->payment_intent_id);
            }

            public function refund(Payments $payment, Order $order, ?float $amount = null, array $context = []): RefundResult
            {
                throw new DomainException('Not used.');
            }

            public function void(Payments $payment, Order $order, array $context = []): VoidResult
            {
                throw new DomainException('Not used.');
            }

            public function verify(Payments $payment, Order $order): VerifyResult
            {
                throw new DomainException('Not used.');
            }
        };
    }
}
