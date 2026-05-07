<?php

declare(strict_types=1);

namespace Kanvas\Connectors\DriveCentric\Enums;

enum CustomFieldEnums: string
{
    case DRIVE_CENTRIC_ID = 'DRIVE_CENTRIC_ID';
    case DRIVE_CENTRIC_CUSTOMER_ID = 'DRIVE_CENTRIC_CUSTOMER_ID';
    case DRIVE_CENTRIC_DEAL_ID = 'DRIVE_CENTRIC_DEAL_ID';
    case DRIVE_CENTRIC_LEAD_ID = 'DRIVE_CENTRIC_LEAD_ID';
    case DRIVE_CENTRIC_USER_ID = 'DRIVE_CENTRIC_USER_ID';
    case VEHICLE_OF_INTEREST = 'vehicle_of_interest';
    case TRADE_IN = 'vehicle_trade_id';
    case DEAL_STAGE = 'DRIVE_CENTRIC_DEAL_STAGE';
    case SOURCE = 'DRIVE_CENTRIC_SOURCE';
}
