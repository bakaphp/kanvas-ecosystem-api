<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Enums;

enum AgentPhoneChannelEnum: string
{
    case VOICE = 'voice';
    case SMS = 'sms';

    public function configColumn(): string
    {
        return match ($this) {
            self::VOICE => 'voice_config',
            self::SMS => 'sms_config',
        };
    }

    public function twilioUrlField(): string
    {
        return match ($this) {
            self::VOICE => 'voiceUrl',
            self::SMS => 'smsUrl',
        };
    }

    public function twilioMethodField(): string
    {
        return match ($this) {
            self::VOICE => 'voiceMethod',
            self::SMS => 'smsMethod',
        };
    }
}
