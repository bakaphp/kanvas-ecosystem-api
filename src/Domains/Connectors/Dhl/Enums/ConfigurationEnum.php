<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Dhl\Enums;

enum ConfigurationEnum: string
{
    case BASE_URL = 'dhl_base_url';
    case USERNAME = 'dhl_username';
    case PASSWORD = 'dhl_password';
    case ACCOUNT_NUMBER = 'dhl_account_number';
    case SIGNATURE_SERVICE = 'dhl_signature_service';
    case LABEL_TEMPLATE = 'dhl_label_template';
    case ORIGIN = 'dhl_origin';
    case REQUEST_PICKUP = 'dhl_request_pickup';
    case PICKUP_CLOSE_TIME = 'dhl_pickup_close_time';
    case CONTENT_DESCRIPTION = 'dhl_content_description';

    public const string DEFAULT_BASE_URL = 'https://express.api.dhl.com/mydhlapi';
    public const string TEST_BASE_URL = 'https://express.api.dhl.com/mydhlapi/test';
    public const string DEFAULT_SIGNATURE_SERVICE = 'SD';
    public const string DEFAULT_LABEL_TEMPLATE = 'ECOM26_84_A4_001';
    public const string DEFAULT_PICKUP_CLOSE_TIME = '18:00';
    public const string DEFAULT_CONTENT_DESCRIPTION = 'Merchandise';
    public const string API_VERSION = '3.4.1';
}
