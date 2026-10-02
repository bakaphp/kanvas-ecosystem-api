<?php

declare(strict_types=1);

namespace Kanvas\Connectors\SalesAssist\Enums;

enum LeadCustomFieldEnum: string
{
    case VEHICLE_OF_INTEREST = 'vehicle_of_interest';
    case TRADE_IN = 'vehicle_trade_id';
    case TRADE_IN_DATA = 'tradein_data';
    case TRADE_IN_IMPORTED = 'tradein_imported';
    case DRIVERS_LICENSE = 'get_docs_drivers_license';
    case DRIVERS_LICENSE_IMAGE = 'driver_license_images';
    case CREDIT_APP = 'credit_app';
    case ADF_LEAD_XML = 'adf_lead_xml';
    case DEALER_TAG_TRIGGER = 'dealer_tag_trigger';
    case DEALER_TAG_OWNER_ID = 'dealer_tag_owner_id';
    case DEALER_TAG_STOCK_NUMBER = 'dealer_tag_stock_number';
}
