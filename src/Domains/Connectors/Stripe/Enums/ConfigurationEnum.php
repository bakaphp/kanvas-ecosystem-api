<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Stripe\Enums;

enum ConfigurationEnum: string
{
    case STRIPE_SECRET_KEY = 'STRIPE_SECRET_KEY';
    case STRIPE_DEFAULT_TRIAL_DAYS = 'STRIPE_DEFAULT_TRIAL_DAYS';
    case STRIPE_USER_ID = 'stripe_id';
    case STRIPE_ACCOUNT_CONNECTED = 'stripe_account_connected';
    CASE STRIPE_ACCOUNT_EMAIL = 'stripe_email';
}
