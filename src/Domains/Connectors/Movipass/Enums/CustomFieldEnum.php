<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Movipass\Enums;

enum CustomFieldEnum: string
{
    case ROADSIDE_ASSISTANCE_PIN = 'movipass_roadside_assistance_pin';
    case ROADSIDE_ASSISTANCE_PIN_HASH = 'movipass_roadside_assistance_pin_hash';
    case MECHANIC_AVAILABILITY = 'movipass_mechanic_availability';
    case MECHANIC_SERVICE_TYPE = 'movipass_mechanic_service_type';
    case ORDER_MECHANIC_USERS_ID = 'movipass_order_mechanic_users_id';
    case MECHANIC_LAT = 'movipass_mechanic_lat';
    case MECHANIC_LNG = 'movipass_mechanic_lng';
    case MECHANIC_VEHICLE_INFO = 'movipass_mechanic_vehicle_info';
    case COMPANY_REGION_ID = 'movipass_region_id';
    case ROADSIDE_PROVIDER_CASE_NUMBER = 'movipass_roadside_provider_case_number';
    case ROADSIDE_PROVIDER_LAST_PUSHED_STATE = 'movipass_roadside_provider_last_pushed_state';
    case ROADSIDE_PROVIDER_LAST_POLLED_AT = 'movipass_roadside_provider_last_polled_at';
    case ROADSIDE_PROVIDER_SNAPSHOT = 'movipass_roadside_provider_snapshot';
    case ROADSIDE_PROVIDER_SYNC_ERROR = 'movipass_roadside_provider_sync_error';
}
