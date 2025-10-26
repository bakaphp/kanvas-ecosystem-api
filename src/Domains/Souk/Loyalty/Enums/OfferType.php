<?php

declare(strict_types=1);

namespace Kanvas\Souk\Loyalty\Enums;

enum OfferType: string
{
    case DISCOUNT = 'discount';
    case POINTS = 'points';
    case EXCLUSIVE = 'exclusive';
    case FREE_SHIPPING = 'free_shipping';
}