<?php

declare(strict_types=1);

namespace Kanvas\Connectors\CMLink\Enums;

enum ConfigurationEnum: string
{
    case BASE_URL = 'CM_LINK_BASE_URL';
    case APP_KEY = 'CM_LINK_APP_KEY';
    case APP_SECRET = 'CM_LINK_APP_SECRET';
    case APP_TYPE = 'CM_LINK_APP_TYPE';
}
