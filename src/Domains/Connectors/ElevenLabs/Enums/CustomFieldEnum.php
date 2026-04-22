<?php

declare(strict_types=1);

namespace Kanvas\Connectors\ElevenLabs\Enums;

enum CustomFieldEnum: string
{
    case CONVERSATION_ID = 'elevenlabs_conversation_id';
    case CALL_FAILURE = 'elevenlabs_call_failure';
}
