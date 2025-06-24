<?php

namespace Kanvas\Payments\Actions;

use Kanvas\Payments\DataTransferObjet\PaymentMethod;
use Kanvas\Payments\Models\PaymentMethods;

class UpdatePaymentMethodAction
{
    public function __construct(
        protected PaymentMethods $paymentMethod,
        protected PaymentMethod $updateData
    ) {
    }

    public function execute(): PaymentMethods
    {
        $paymentMethod = $this->paymentMethod;

        $paymentMethod->payment_methods_brand = $this->updateData->payment_methods_brand;
        $paymentMethod->payment_ending_numbers = $this->updateData->payment_ending_numbers;
        $paymentMethod->expiration_date = $this->updateData->expiration_date;
        $paymentMethod->zip_code = $this->updateData->zip_code;
        $paymentMethod->stripe_card_id = $this->updateData->stripe_card_id;
        $paymentMethod->processor = $this->updateData->processor;
        $paymentMethod->metadata = $this->updateData->metadata;
        $paymentMethod->save();

        return $paymentMethod;
    }
}
