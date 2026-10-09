<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Stripe\Enums;

enum ConfigurationEnum: string
{
    case STRIPE_SECRET_KEY = 'STRIPE_SECRET_KEY';
    case STRIPE_DEFAULT_TRIAL_DAYS = 'STRIPE_DEFAULT_TRIAL_DAYS';
    case STRIPE_USER_ID = 'stripe_id';
    case STRIPE_ACCOUNT_CONNECTED = 'stripe_account_connected';
    case STRIPE_ACCOUNT_EMAIL = 'stripe_email';
    case CHECKOUT_SUCCESS_URL = 'CHECKOUT_SUCCESS_URL';
    case CHECKOUT_CANCEL_URL = 'CHECKOUT_CANCEL_URL';
    case STRIPE_PUBLISHABLE_KEY = 'stripe_publishable_key';
    case STRIPE_WEBHOOK_SECRET = 'stripe_webhook_secret';
    case STRIPE_WEBHOOK_ENDPOINT_ID = 'stripe_webhook_endpoint_id';
    case STRIPE_SHARED_APP_ACCOUNT = 'stripe_shared_app_account';
    case PAYMENT_LINK_RECEIVER_ID = 'stripe_payment_link_receiver_id';
}
