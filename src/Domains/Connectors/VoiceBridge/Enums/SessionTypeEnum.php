<?php

declare(strict_types=1);

namespace Kanvas\Connectors\VoiceBridge\Enums;

enum SessionTypeEnum: string
{
    case OUTBOUND = 'outbound';
    case INBOUND = 'inbound';
    case WEB = 'web';
}
