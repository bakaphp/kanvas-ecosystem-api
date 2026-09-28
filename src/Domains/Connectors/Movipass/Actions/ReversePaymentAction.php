<?php

namespace Kanvas\Connectors\Movipass\Actions;

use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\EchoPay\Enums\CustomFieldEnum;
use Kanvas\Connectors\Movipass\Jobs\RetryPaymentReversalJob;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Payments\Models\Payments;
use Kanvas\Souk\Payments\Providers\PortalPaymentProcessor;

class ReversePaymentAction
{
    public function __construct(
        protected Apps $app,
        protected Payments $payment,
        protected Order $order,
        protected ?PortalPaymentProcessor $paymentProcessor = null,
    ) {
    }

    public function execute(string $reason): array
    {
        $paymentProcessor = $this->paymentProcessor ?? new PortalPaymentProcessor(
            $this->app,
            $this->payment->company,
            []
        );

        $intentId = $this->order->get(CustomFieldEnum::ECHO_PAY_PAYMENT_INTENT_ID->value);
        $bankTransaction = explode(':', $intentId)[1];
        $response = $paymentProcessor->reversePayment($this->payment, $this->order, $bankTransaction, $reason);

        if ($response['status'] === 'success' && ($this->payment->metadata[RetryPaymentReversalJob::PENDING_KEY] ?? false)) {
            $this->payment->addMetadata([RetryPaymentReversalJob::PENDING_KEY => false]);
            $this->payment->saveQuietly();
        }

        return [
            'status' => $response['status'],
            'message' => $response['message'] . ' - ' . $reason,
            'data' => $response['data'],
        ];
    }
}
