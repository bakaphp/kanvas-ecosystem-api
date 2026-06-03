<?php

declare(strict_types=1);

namespace Tests\Connectors\Traits;

use Baka\Contracts\AppInterface;
use Kanvas\Connectors\Stripe\Enums\ConfigurationEnum;

trait HasStripeConfiguration
{
    public function setupStripeConfiguration(AppInterface $app): void
    {
        $app->set(
            ConfigurationEnum::STRIPE_SECRET_KEY->value,
            $this->requireStripeTestKey()
        );
    }
}
