<?php

declare(strict_types=1);

namespace Kanvas\Souk\Loyalty\Enums;

enum TriggerType: string
{
    case FIRST_PURCHASE = 'first_purchase';
    case FIRST_PRODUCT_TYPE = 'first_product_type';
    case FIRST_CATEGORY = 'first_category';
    case FIRST_TIER_PURCHASE = 'first_tier_purchase';
    case TIER_UPGRADE = 'tier_upgrade';
    case BIRTHDAY = 'birthday';
    case MILESTONE = 'milestone';
    case REFERRAL = 'referral';
    case SEASONAL = 'seasonal';
    case SOCIAL_ACTION = 'social_action';
    case MANUAL = 'manual';

    /**
     * Get human-readable description of the trigger type.
     */
    public function getDescription(): string
    {
        return match($this) {
            self::FIRST_PURCHASE => 'First Purchase Ever',
            self::FIRST_PRODUCT_TYPE => 'First Purchase by Product Type',
            self::FIRST_CATEGORY => 'First Purchase by Category',
            self::FIRST_TIER_PURCHASE => 'First Purchase in Specific Tier',
            self::TIER_UPGRADE => 'Tier Upgrade Achievement',
            self::BIRTHDAY => 'Birthday Celebration',
            self::MILESTONE => 'Milestone Achievement',
            self::REFERRAL => 'Referral Action',
            self::SEASONAL => 'Seasonal Campaign',
            self::SOCIAL_ACTION => 'Social Media Action',
            self::MANUAL => 'Manual Assignment',
        };
    }
}