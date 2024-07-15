<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Stripe\Enums;

enum ConfigurationEnum: string
{
    case STRIPE_SECRET_KEY = 'STRIPE_SECRET_KEY';
}
