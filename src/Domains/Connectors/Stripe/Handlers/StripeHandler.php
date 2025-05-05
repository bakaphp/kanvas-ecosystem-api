<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Stripe\Handlers;

use Kanvas\Connectors\Contracts\BaseIntegration;
use Kanvas\Connectors\Stripe\Enums\ConfigurationEnum;
use Kanvas\Exceptions\ValidationException;
use Override;
use Stripe\Balance;
use Stripe\Exception\AuthenticationException;
use Stripe\Stripe;

class StripeHandler extends BaseIntegration
{
    #[Override]
    public function setup(): bool
    {
        $stripeSecretKey = $this->data['stripe_secret_key'] ?? null;

        if (empty($stripeSecretKey)) {
            throw new ValidationException('Stripe secret key is required.');
        }

        $this->app->set(ConfigurationEnum::STRIPE_SECRET_KEY->value, $stripeSecretKey);

        // Set the API key
        Stripe::setApiKey($stripeSecretKey);

        // Verify that the key is valid by making a test request
        try {
            // The Balance API is a lightweight call that will validate the key
            Balance::retrieve();

            return true;
        } catch (AuthenticationException $e) {
            throw new ValidationException('Invalid Stripe API key: ' . $e->getMessage());
        }
    }
}
