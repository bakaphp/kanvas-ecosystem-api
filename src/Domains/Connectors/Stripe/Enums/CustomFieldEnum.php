<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Stripe\Enums;

enum CustomFieldEnum: string
{
    case STRIPE_ID = 'stripe_id';
    case STRIPE_CUSTOMER_ID = 'STRIPE_CUSTOMER_ID';
    case STRIPE_PAYMENT_METHOD_ID = 'STRIPE_PAYMENT_METHOD_ID';
    case STRIPE_PAYMENT_INTENT_ID = 'STRIPE_PAYMENT_INTENT_ID';
    case STRIPE_CHARGE_ID = 'STRIPE_CHARGE_ID';
    case STRIPE_PAYMENT_LINK_ID = 'stripe_payment_link_id';
    case STRIPE_PAYMENT_LINK_URL = 'stripe_payment_link_url';
    case STRIPE_CHECKOUT_SESSION_ID = 'stripe_checkout_session_id';
}
