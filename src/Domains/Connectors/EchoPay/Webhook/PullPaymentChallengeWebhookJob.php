<?php

declare(strict_types=1);

namespace Kanvas\Connectors\EchoPay\Webhook;

use Exception;
use Kanvas\Connectors\Movipass\Actions\ProcessPaymentAction;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Payments\Models\Payments;
use Kanvas\Workflow\Jobs\ProcessWebhookJob;
use Override;

class PullPaymentChallengeWebhookJob extends ProcessWebhookJob
{
    #[Override]
    public function execute(): array
    {
        $orderId = $this->webhookRequest->payload['orderId'];
        $payload = $this->webhookRequest->payload;

        if (! $orderId) {
            return [
                'message' => 'Not a NetSuite Company',
            ];
        }

        $payment = Payments::where([
            'apps_id' => $this->receiver->app->getId(),
            'payable_id' => $orderId,
            'payable_type' => Order::class,
        ])->first();

        if (! $payment) {
            throw new Exception('Payment not found');
        }

        $order = $payment->order;

        $paymentProcessor = new PortalPaymentProcessor(
            $app,
            $payment->company,
            []
        );

        $paymentProcessor->validatePayerAuthResult($payment, $order);

        $result = new ProcessPaymentAction($this->receiver->app, $payment, $order)->execute($enrollmentResult['data']);

        return [
            'status' => $result['status'],
            'message' => $result['message'],
            'data' => $result['data'],
        ];
       

        return [
            'message' => 'NetSuite Company Synced',
            'payload' => $payload,
        ];
    }
}
