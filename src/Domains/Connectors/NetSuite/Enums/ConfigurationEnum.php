<?php

declare(strict_types=1);

namespace Kanvas\Connectors\NetSuite\Enums;

enum ConfigurationEnum: string
{
    case NET_SUITE_ACCOUNT_CONFIG = 'NET_SUITE_ACCOUNT_CONFIG';
    case NET_SUITE_CUSTOM_API_URL = 'NET_SUITE_CUSTOM_API_URL';
}
