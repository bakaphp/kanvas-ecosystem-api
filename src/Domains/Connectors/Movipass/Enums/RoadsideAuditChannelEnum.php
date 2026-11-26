<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Movipass\Enums;

enum RoadsideAuditChannelEnum: string
{
    case WEB = 'web';
    case APK = 'apk';
    case APP = 'app';
    case API = 'api';
    case SYSTEM = 'system';
}
