<?php

declare(strict_types=1);

namespace Kanvas\Connectors\ElevenLabs\Enums;

enum WebhookTypeEnum: string
{
    case POST_CALL_TRANSCRIPTION = 'post_call_transcription';
    case POST_CALL_AUDIO = 'post_call_audio';
    case CALL_INITIATION_FAILURE = 'call_initiation_failure';
}
