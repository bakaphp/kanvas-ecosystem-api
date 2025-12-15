<?php

namespace Kanvas\Connectors\Movipass\Actions;

use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\EchoPay\Enums\CustomFieldEnum;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Payments\Enums\PaymentStatusEnum;
use Kanvas\Souk\Payments\Models\Payments;
use Kanvas\Souk\Payments\Providers\PortalPaymentProcessor;

class ReversePaymentAction
{
    public function __construct(
        protected Apps $app,
        protected Payments $payment,
        protected Order $order
    ) {
    }

    public function execute(string $reason): array
    {
        $paymentProcessor = new PortalPaymentProcessor(
            $this->app,
            $this->payment->company,
            []
        );

        $result = [
            'status' => 'success',
            'message' => 'Payment processed successfully',
            'data' => [],
        ];

        $intentId = $this->order->get(CustomFieldEnum::ECHO_PAY_PAYMENT_INTENT_ID->value);
        $bankTransaction = explode(':', $intentId)[1];
        $response = $paymentProcessor->reversePayment($this->payment, $this->order, $bankTransaction, $reason);
        $result['status'] = PaymentStatusEnum::FAILED->value;
        $result['message'] = $response['message'] . ' - ' . $reason;
        $result['data'] = $response['data'];

        return $result;
    }
}
