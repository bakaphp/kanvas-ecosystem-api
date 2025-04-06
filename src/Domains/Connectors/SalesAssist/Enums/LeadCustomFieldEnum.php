<?php

declare(strict_types=1);

namespace Kanvas\Connectors\SalesAssist\Enums;

enum LeadCustomFieldEnum: string
{
    case VEHICLE_OF_INTEREST = 'vehicle_of_interest';
    case TRADE_IN = 'vehicle_trade_id';
    case DRIVERS_LICENSE = 'get_docs_drivers_license';
    case DRIVERS_LICENSE_IMAGE = 'driver_license_images';
    case CREDIT_APP = 'credit_app';
}
