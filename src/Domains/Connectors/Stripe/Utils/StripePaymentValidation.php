<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Stripe\Utils;

use Stripe\StripeClient;

class StripePaymentValidation
{
    const VALID_PAYMENT_STATUSES = [
        'succeeded',
        'requires_capture',
    ];

    const INVALID_PAYMENT_STATUSES = [
        'requires_payment_method',
        'requires_confirmation',
        'canceled',
    ];

    protected StripeClient $stripe;

    public function isValidPayment(string $status): bool
    {
        return in_array($status, self::VALID_PAYMENT_STATUSES);
    }

        public function isInvalid(string $status): bool
    {
        return in_array($status, self::INVALID_PAYMENT_STATUSES);
    }

    public function getStatusMessage(string $status): string
    {
        return match($status) {
            'succeeded' => 'Payment completed successfully',
            'requires_capture' => 'Payment authorized, ready to capture',
            'requires_payment_method' => 'No payment method attached',
            'requires_confirmation' => 'Payment needs confirmation',
            'canceled' => 'Payment was canceled',
            default => "Unknown status: {$status}"
        };
    }
}
