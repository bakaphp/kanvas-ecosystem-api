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

        return match ($prefix) {
            'showroom' => 'showroom_first_message_default',
            'phone' => 'phone_first_message_default',
            default => 'internet_first_message_default',
        };
    }

    public static function getAiModeDefaultKey(Lead $lead): string
    {
        $prefix = self::getTypePrefix($lead->type()->first());

        return match ($prefix) {
            'showroom' => 'showroom_ai_mode_default',
            'phone' => 'phone_ai_mode_default',
            default => 'internet_ai_mode_default',
        };
    }

    public static function getFollowUpDefaultKey(Lead $lead): string
    {
        $prefix = self::getTypePrefix($lead->type()->first());
        $statusSuffix = self::getStatusSuffix($lead);

        if ($statusSuffix !== '') {
            return "{$prefix}_followup_default_{$statusSuffix}";
        }

        return match ($prefix) {
            'showroom' => 'showroom_followup_default_mode',
            'phone' => 'phone_followup_default_mode',
            default => 'internet_followup_default_mode',
        };
    }
}
