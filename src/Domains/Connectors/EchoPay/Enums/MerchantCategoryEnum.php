<?php

declare(strict_types=1);

namespace Kanvas\Connectors\EchoPay\Enums;

enum MerchantCategoryEnum: string
{
    case RETAIL = "RETAIL";
    case FAST_FOOD = "FAST_FOOD";
    case TELECOM = "TELECOM";
    case ELECTRONICA = "ELECTRONICA";
    case TICKETS = "TICKETS";
    case TRAVEL_AGENCY = "TRAVEL_AGENCY";
}
