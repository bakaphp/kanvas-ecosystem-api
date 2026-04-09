<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Services;

use Kanvas\Guild\Leads\Models\LeadType;

class LeadTypeConfigurationService
{
    public static function getAiModeKey(?LeadType $leadType): string
    {
        $name = strtolower($leadType?->name ?? '');

        if (str_contains($name, 'showroom')) {
            return 'showroom_ai_mode';
        }

        if (str_contains($name, 'phone')) {
            return 'phone_ai_mode';
        }

        return 'ai_mode';
    }

    public static function getFollowUpModeKey(?LeadType $leadType): string
    {
        $name = strtolower($leadType?->name ?? '');

        if (str_contains($name, 'showroom')) {
            return 'showroom_follow_up_mode';
        }

        if (str_contains($name, 'phone')) {
            return 'phone_follow_up_mode';
        }

        return 'internet_follow_up_mode';
    }

    public static function getFirstMessageDefaultKey(?LeadType $leadType): string
    {
        $name = strtolower($leadType?->name ?? '');

        if (str_contains($name, 'showroom')) {
            return 'showroom_first_message_default';
        }

        if (str_contains($name, 'phone')) {
            return 'phone_first_message_default';
        }

        return 'internet_first_message_default';
    }

    public static function getAiModeDefaultKey(?LeadType $leadType): string
    {
        $name = strtolower($leadType?->name ?? '');

        if (str_contains($name, 'showroom')) {
            return 'showroom_ai_mode_default';
        }

        if (str_contains($name, 'phone')) {
            return 'phone_ai_mode_default';
        }

        return 'internet_ai_mode_default';
    }

    public static function getFollowUpDefaultKey(?LeadType $leadType): string
    {
        $name = strtolower($leadType?->name ?? '');

        if (str_contains($name, 'showroom')) {
            return 'showroom_followup_default_mode';
        }

        if (str_contains($name, 'phone')) {
            return 'phone_followup_default_mode';
        }

        return 'internet_followup_default_mode';
    }
}
