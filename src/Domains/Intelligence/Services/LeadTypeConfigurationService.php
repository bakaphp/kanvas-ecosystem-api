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
}
