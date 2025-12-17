<?php

declare(strict_types=1);

namespace Kanvas\Connectors\ChromeData\Enums;

enum ConfigurationEnum: string
{
    case NAME = 'ChromeData';
    case ACCOUNT_NUMBER = 'chromedata_account_number';
    case ACCOUNT_SECRET = 'chromedata_account_secret';
    case COUNTRY = 'chromedata_country';
    case LANGUAGE = 'chromedata_language';
    case WSDL_URL = 'chromedata_wsdl_url';
}
