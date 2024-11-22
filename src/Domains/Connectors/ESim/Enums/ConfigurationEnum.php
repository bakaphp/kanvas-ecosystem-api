<?php

declare(strict_types=1);

namespace Kanvas\Connectors\ESim\Enums;

enum ConfigurationEnum: string
{
    case BASE_URL = 'ESIM_BASE_URL';
    case APP_TOKEN = 'ESIM_APP_TOKEN';
    case APP_CHANNEL_ID = 'ESIM_CHANNEL_ID';
}
