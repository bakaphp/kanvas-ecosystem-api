<?php

declare(strict_types=1);

namespace Tests\Connectors\Support;

use Kanvas\Connectors\Movipass\Parking\Support\ParkingMetadata;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Payments\Enums\PaymentStatusEnum;
use Kanvas\Souk\Payments\Models\Payments;

trait RecordsParkingPayments
{
    private function addPayment(
        Order $order,
        float $amount,
        PaymentStatusEnum $status = PaymentStatusEnum::PAID
    ): Payments {
        $payment = new Payments();
        $payment->apps_id = $order->apps_id;
        $payment->companies_id = $order->companies_id;
        $payment->users_id = $order->users_id;
        $payment->payment_methods_id = 0;
        $payment->payable_id = $order->getId();
        $payment->payable_type = Order::class;
        $payment->concept = 'Parking exit';
        $payment->payment_date = date('Y-m-d');
        $payment->amount = $amount;
        $payment->currency = 'USD';
        $payment->status = $status->value;
        $payment->processor = 'test';
        $payment->metadata = ['data' => []];
        $payment->saveOrFail();

        return $payment;
    }

    private function addPaidPaymentForQuote(Order $order): Payments
    {
        return $this->addPayment(
            $order,
            ParkingMetadata::of($order->refresh())[ParkingMetadata::QUOTE]['amount_due']
        );
    }
}
