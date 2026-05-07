<?php

declare(strict_types=1);

namespace Tests\Connectors\Traits;

use Baka\Contracts\AppInterface;
use Exception;
use Kanvas\Connectors\Stripe\Enums\ConfigurationEnum;

trait HasStripeConfiguration
{
    public function setupStripeConfiguration(AppInterface $app): void
    {
        if (empty($app->get(ConfigurationEnum::STRIPE_SECRET_KEY->value))) {
            if (! getenv('TEST_STRIPE_SECRET_KEY')) {
                throw new Exception('Missing Stripe Test Keys');
            }
            $app->set(ConfigurationEnum::STRIPE_SECRET_KEY->value, getenv('TEST_STRIPE_SECRET_KEY'));
        }
    }
}
