<?php

declare(strict_types=1);

namespace Tests\GraphQL\Souk;

use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Orders\Models\OrderTypes;
use Kanvas\Souk\Payments\Enums\PaymentStatusEnum;
use Kanvas\Souk\Payments\Jobs\RetryPaymentCaptureJob;
use Kanvas\Souk\Payments\Models\Payments;
use Kanvas\Souk\Payments\Notifications\PaymentReceiptNotification;

final class RetryPaymentCaptureJobTest extends PortalMockFlowCase
{
    public function testARetriedCaptureMarksThePaymentPaidClearsTheMarkerAndSendsTheConfiguredReceipt(): void
    {
        $service = $this->portalServiceWithRealMockClient();
        $service->shouldReceive('capturePayment')->once()->passthru();
        $service->shouldNotReceive('reversePayment');
        $paymentId = $this->createAuthorizedPortalPayment($service);
        $payment = Payments::find($paymentId);
        $order = Order::find($payment->payable_id);
        $order->setOrderType('parking');
        $order->orderType->update([
            'config' => [OrderTypes::PAYMENT_RECEIPT_CONFIG_KEY => ['template' => 'parking_receipt']],
        ]);
        $payment->addMetadata([
            RetryPaymentCaptureJob::PENDING_KEY => true,
            'capture_attempts' => 1,
            'capture_reason' => 'Capture failed: gateway down',
        ]);
        $payment->saveQuietly();
        Notification::fake();

        new RetryPaymentCaptureJob($this->apps, $payment)->handle();

        $payment = $payment->fresh();
        $this->assertSame(PaymentStatusEnum::PAID->value, $payment->status);
        $this->assertFalse($payment->metadata[RetryPaymentCaptureJob::PENDING_KEY]);
        Notification::assertSentTo(
            new AnonymousNotifiable(),
            PaymentReceiptNotification::class,
            fn (PaymentReceiptNotification $notification): bool => $notification->getTemplateName() === 'parking_receipt'
        );
    }
}
