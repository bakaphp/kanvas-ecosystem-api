<?php

declare(strict_types=1);

namespace Kanvas\Subscription\Enums;

enum SubscriptionProviderEnum: string
{
    case STRIPE = 'stripe';
    case APPLE = 'apple';
    case GOOGLE = 'google';
}
