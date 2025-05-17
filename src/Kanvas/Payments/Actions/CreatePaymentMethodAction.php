<?php

namespace Kanvas\Payments\Actions;

use Kanvas\Payments\DataTransferObjet\PaymentMethod;
use Kanvas\Payments\Models\PaymentMethods;

class CreatePaymentMethodAction
{
    public function __construct(
        protected PaymentMethod $paymentMethod
    ) {}

    public function execute(): PaymentMethods
    {
        $paymentMethod = new PaymentMethods();
        $paymentMethod->apps_id = $this->paymentMethod->app->getId();
        $paymentMethod->companies_id = $this->paymentMethod->company->getId();
        $paymentMethod->users_id = $this->paymentMethod->user->getId();
        $paymentMethod->payment_methods_brand = $this->paymentMethod->payment_methods_brand;
        $paymentMethod->payment_ending_numbers = $this->paymentMethod->payment_ending_numbers;
        $paymentMethod->expiration_date = $this->paymentMethod->expiration_date;
        $paymentMethod->zip_code = $this->paymentMethod->zip_code;
        $paymentMethod->stripe_card_id = $this->paymentMethod->stripe_card_id;
        $paymentMethod->processor = $this->paymentMethod->processor;
        $paymentMethod->metadata = $this->paymentMethod->metadata;
        $paymentMethod->save();
        return $paymentMethod;
    }
}
