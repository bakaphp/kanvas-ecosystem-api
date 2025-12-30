<?php

declare(strict_types=1);

namespace Kanvas\Connectors\DealerSocket\Enums;

enum ConfigurationEnum: string
{
    case LEAD_TIME_DIFF_MINUTES = 'DEALER_SOCKET_LEAD_TIME_DIFF_MINUTES';
    case DEALER_SOCKET_DEFAULT_URL = 'DEALER_SOCKET_DEFAULT_URL';
    case DEALER_SOCKET_USE_OEM_TESTING_URL = 'DEALER_SOCKET_USE_OEM_TESTING_URL';
}
