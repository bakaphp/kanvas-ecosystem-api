<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\PriceDisclosure\Enums;

enum PriceDisclosureReasonEnum: string
{
    case VEHICLE_UNRESOLVED = 'vehicle_unresolved';
    case PRICE_MISSING = 'price_missing';
    case TEMPLATE_MISSING = 'template_missing';
    case PRICE_UNVERIFIED = 'price_unverified';
    case DISCLOSURE_MISSING = 'disclosure_missing';
    case PAYMENT_UNSUPPORTED = 'payment_unsupported';
    case ADD_ON_DISCLOSURE_MISSING = 'add_on_disclosure_missing';
}
