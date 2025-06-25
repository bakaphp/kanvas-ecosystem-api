<?php

declare(strict_types=1);

namespace Kanvas\Connectors\EchoPay\Webhook;

use Exception;
use Kanvas\Connectors\EchoPay\Enums\CustomFieldEnum;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Payments\Models\Payments;
use Kanvas\Workflow\Jobs\ProcessWebhookJob;
use Override;

class PullPaymentChallengeWebhookJob extends ProcessWebhookJob
{
    #[Override]
    public function execute(): array
    {
        $payload = $this->webhookRequest->payload;
        $orderId = $this->webhookRequest->payload['MD'];
        $transactionId = $this->webhookRequest->payload['TransactionId'];


        if (! $orderId) {
            return [
                'message' => 'Not a valid order',
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

        $order->set(CustomFieldEnum::ECHO_PAY_AUTH_TRANSACTION_ID->value, $transactionId);

        return [
            'message' => 'Transaction id received',
            'html' => '<script>window.parent.postMessage({type: "payment_complete", status: "success", message: "Transaction id received"}, "*"); window.close();</script>',
        ];
    }
}
