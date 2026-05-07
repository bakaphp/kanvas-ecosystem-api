<?php

declare(strict_types=1);

namespace Kanvas\Connectors\VoiceBridge\Enums;

enum ConfigurationEnum: string
{
    case API_KEY = 'voice_bridge_api_key';
    case BASE_URL = 'voice_bridge_base_url';
    case COMPANY_ID = 'voice_bridge_company_id';
    case VOICE_NO_RESPONSE_MINUTES = 'voice_bridge_no_response_minutes';
    case TRANSCRIPT_DELAY_MINUTES = 'voice_bridge_transcript_delay_minutes';
}
