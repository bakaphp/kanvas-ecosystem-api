<?php

namespace Kanvas\Connectors\Movipass\Actions;

use Exception;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\EchoPay\Enums\CustomFieldEnum;
use Kanvas\Souk\Orders\Actions\TransitionOrderStateAction;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Payments\Enums\PaymentStatusEnum;
use Kanvas\Souk\Payments\Models\Payments;
use Kanvas\Souk\Payments\Providers\PortalPaymentProcessor;

class CapturePaymentAction
{
    public function __construct(
        protected Apps $app,
        protected Payments $payment,
        protected Order $order
    ) {
    }

    public function execute(): array
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
        $paymentProcessor->capturePayment($this->payment, $this->order, $bankTransaction);
        try {
            if ($orderStatus = $this->order->orderType?->statuses()->where('slug', PaymentStatusEnum::PAID->value)->first()) {
                new TransitionOrderStateAction(
                    $this->order,
                    $this->payment->user,
                    $orderStatus
                )->execute(true);
            }
            new SendPaymentReceiptAction(
                $this->order,
                $this->payment,
                $this->payment->user
            )->execute();
        } catch (Exception $e) {
            report($e);
        }

        return $result;
    }
}
