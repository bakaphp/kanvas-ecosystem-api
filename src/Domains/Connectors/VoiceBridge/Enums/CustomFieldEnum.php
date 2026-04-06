<?php

declare(strict_types=1);

namespace Kanvas\Connectors\VoiceBridge\Enums;

enum CustomFieldEnum: string
{
    case SESSION_ID = 'VOICE_BRIDGE_SESSION_ID';
    case CALL_SID = 'VOICE_BRIDGE_CALL_SID';
}
