<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Stripe\Webhooks\Concerns;

use Illuminate\Http\Request;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Webhook;
use UnexpectedValueException;

trait VerifiesStripeSignature
{
    protected static function hasValidStripeSignature(Request $request, ?string $secret): bool
    {
        $signature = $request->header('Stripe-Signature');

        if ((string) $secret === '' || ! is_string($signature) || $signature === '') {
            return false;
        }

        try {
            Webhook::constructEvent($request->getContent(), $signature, $secret);
        } catch (SignatureVerificationException|UnexpectedValueException) {
            return false;
        }

        return true;
    }
}
