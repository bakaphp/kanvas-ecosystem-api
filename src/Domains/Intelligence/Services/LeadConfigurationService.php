<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Services;

use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Leads\Models\LeadType;

class LeadConfigurationService
{
    private static function getTypePrefix(?LeadType $leadType): string
    {
        $name = strtolower($leadType?->name ?? '');

        if (str_contains($name, 'showroom')) {
            return 'showroom';
        }

        if (str_contains($name, 'phone')) {
            return 'phone';
        }

        return 'internet';
    }

    private static function getStatusSuffix(Lead $lead): string
    {
        $statusName = strtolower($lead->status()->first()?->name ?? '');

        if (str_contains($statusName, 'not') && str_contains($statusName, 'sold')) {
            return 'closed-not-sold';
        }

        if (str_contains($statusName, 'sold')) {
            return 'closed-sold';
        }

        return '';
    }

    public static function getAiModeKey(Lead $lead): string
    {
        $prefix = self::getTypePrefix($lead->type()->first());

        return match ($prefix) {
            'showroom' => 'showroom_ai_mode',
            'phone' => 'phone_ai_mode',
            default => 'ai_mode',
        };
    }

    public static function getFollowUpModeKey(Lead $lead): string
    {
        $prefix = self::getTypePrefix($lead->type()->first());
        $statusSuffix = self::getStatusSuffix($lead);

        if ($statusSuffix !== '') {
            return "{$prefix}_followup_{$statusSuffix}";
        }

        return match ($prefix) {
            'showroom' => 'showroom_follow_up_mode',
            'phone' => 'phone_follow_up_mode',
            default => 'internet_follow_up_mode',
        };
    }

    public static function getFirstMessageDefaultKey(Lead $lead): string
    {
        $prefix = self::getTypePrefix($lead->type()->first());

        return "{$prefix}_first_fu_active_default";
    }

    public static function getAiModeDefaultKey(Lead $lead): string
    {
        $prefix = self::getTypePrefix($lead->type()->first());
        $statusSuffix = self::getStatusSuffix($lead);

        $state = $statusSuffix !== '' ? 'closed' : 'open';

        return "{$prefix}_ai_mode_{$state}_default";
    }

    public static function getFollowUpDefaultKey(Lead $lead): string
    {
        $prefix = self::getTypePrefix($lead->type()->first());
        $statusSuffix = self::getStatusSuffix($lead);

        if ($statusSuffix === 'closed-not-sold') {
            return "{$prefix}_con_fu_cns_default";
        }

        if ($statusSuffix === 'closed-sold') {
            return "{$prefix}_con_fu_closed-sold_default";
        }

        return "{$prefix}_con_fu_active_default";
    }
}
