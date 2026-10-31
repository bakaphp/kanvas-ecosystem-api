<?php

declare(strict_types=1);

namespace Tests\Souk\Payments;

use Illuminate\Broadcasting\Channel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Payments\Enums\PaymentStatusEnum;
use Kanvas\Souk\Payments\Events\PaymentStatusChanged;
use Kanvas\Souk\Payments\Models\Payments;
use Kanvas\Souk\Payments\Observers\PaymentObserver;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class PaymentStatusChangedBroadcastTest extends TestCase
{
    public static function finalStatuses(): array
    {
        return [
            'paid' => [PaymentStatusEnum::PAID],
            'failed' => [PaymentStatusEnum::FAILED],
            'reversed' => [PaymentStatusEnum::REVERSED],
            'cancelled' => [PaymentStatusEnum::CANCELLED],
        ];
    }

    public static function nonFinalStatuses(): array
    {
        return [
            'pending' => [PaymentStatusEnum::PENDING],
            'waiting device data' => [PaymentStatusEnum::WAITING_DEVICE_DATA],
            'pending authorization' => [PaymentStatusEnum::PENDING_AUTHORIZATION],
            'processing' => [PaymentStatusEnum::PROCESSING],
            'authorized' => [PaymentStatusEnum::AUTHORIZED],
        ];
    }

    #[DataProvider('finalStatuses')]
    public function testReachingAFinalStatusBroadcastsOnTheOrderChannel(PaymentStatusEnum $status): void
    {
        Event::fake([PaymentStatusChanged::class]);
        $order = $this->order();
        $payment = $this->paymentFor($order, $status);

        new PaymentObserver()->updated($payment);

        Event::assertDispatched(
            PaymentStatusChanged::class,
            function (PaymentStatusChanged $event) use ($payment, $order, $status): bool {
                $channel = $event->broadcastOn();

                return $channel instanceof Channel
                    && $channel->name === 'order-' . $order->uuid
                    && $event->broadcastWith() === [
                        'payment_id' => $payment->getId(),
                        'status' => $status->value,
                        'order_id' => $order->getId(),
                    ];
            }
        );
    }

    #[DataProvider('nonFinalStatuses')]
    public function testANonFinalStatusDoesNotBroadcast(PaymentStatusEnum $status): void
    {
        Event::fake([PaymentStatusChanged::class]);

        new PaymentObserver()->updated($this->paymentFor($this->order(), $status));

        Event::assertNotDispatched(PaymentStatusChanged::class);
    }

    public function testAnUnchangedFinalStatusDoesNotBroadcast(): void
    {
        Event::fake([PaymentStatusChanged::class]);
        $payment = $this->paymentFor($this->order(), PaymentStatusEnum::PAID);
        $payment->syncOriginal();

        new PaymentObserver()->updated($payment);

        Event::assertNotDispatched(PaymentStatusChanged::class);
    }

    public function testAPayableThatIsNotAnOrderDoesNotBroadcast(): void
    {
        Event::fake([PaymentStatusChanged::class]);
        $payment = $this->paymentFor(new class () extends Model {
        }, PaymentStatusEnum::PAID);

        new PaymentObserver()->updated($payment);

        Event::assertNotDispatched(PaymentStatusChanged::class);
    }

    private function order(): Order
    {
        $order = new Order();
        $order->id = 987;
        $order->uuid = 'order-uuid-1';

        return $order;
    }

    private function paymentFor(Model $payable, PaymentStatusEnum $status): Payments
    {
        $payment = new Payments();
        $payment->id = 555;
        $payment->status = PaymentStatusEnum::PROCESSING->value;
        $payment->syncOriginal();
        $payment->status = $status->value;
        $payment->setRelation('payable', $payable);

        return $payment;
    }
}
