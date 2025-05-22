<?php

namespace Kanvas\Souk\Payments\Actions;

use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Payments\Models\Payments;
use Kanvas\Souk\Payments\Providers\AuthorizePortalPaymentProcessor;

class CreatePaymentAction
{

    public function __construct(
        protected Order $order,
    ) {
    }

    public function execute($formData = []): Payments
    {
        $formData = [
            "amount" => $this->order->getTotalAmount(),
            "payment_date" => $formData['payment_date'] ?? date("Y-m-d"),
            "document_date" => $this->order->created_at ?? date("Y-m-d"),
            "concept" => $formData['concept'] ?? "Payment {$this->order->reference}",
            "payment_methods_id" => $formData['payment_methods_id'] ?? $this->order->payment_method_id,
            'users_id' => $this->order->users_id,
            'companies_id' => $this->order->companies_id,
            'currency' => $this->order->currency,
            'status' => 'verified'
        ];
    
        $payment = $this->order->payments()->create($formData);
        return $payment;
    }   
}
