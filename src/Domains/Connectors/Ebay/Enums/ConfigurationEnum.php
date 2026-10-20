<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Ebay\Enums;

enum ConfigurationEnum: string
{
    case EBAY_CLIENT_ID = 'ebay_client_id';
    case EBAY_CLIENT_SECRET = 'ebay_client_secret';
    case EBAY_DEV_ID = 'ebay_dev_id';
    case EBAY_REDIRECT_URI = 'ebay_redirect_uri';
    case EBAY_ENVIRONMENT = 'ebay_environment';
    case EBAY_RU_NAME = 'ebay_ru_name';
}
