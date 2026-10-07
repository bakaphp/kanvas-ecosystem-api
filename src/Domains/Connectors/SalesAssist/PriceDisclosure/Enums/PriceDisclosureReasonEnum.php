<?php

declare(strict_types=1);

namespace Kanvas\Connectors\SalesAssist\PriceDisclosure\Enums;

enum PriceDisclosureReasonEnum: string
{
    case VEHICLE_UNRESOLVED = 'vehicle_unresolved';
    case PRICE_MISSING = 'price_missing';
    case PAYMENT_UNSUPPORTED = 'payment_unsupported';
    case ADD_ON_DISCLOSURE_MISSING = 'add_on_disclosure_missing';
    case OUT_THE_DOOR_UNSUPPORTED = 'out_the_door_unsupported';
}
