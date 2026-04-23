<?php

declare(strict_types=1);

namespace Kanvas\Souk\Payments\Contracts;

use Kanvas\Payments\Models\PaymentMethods;
use Kanvas\Souk\Payments\DataTransferObject\ActivateResult;

interface ActivationProcessorInterface
{
    /**
     * Activate a payment method using a verification code sent to the cardholder.
     */
    public function activatePaymentMethod(PaymentMethods $paymentMethod, string $activationCode): ActivateResult;
}
