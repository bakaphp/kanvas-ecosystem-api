<?php

declare(strict_types=1);

namespace Kanvas\Souk\Loyalty\Enums;

enum SelectionReason: string
{
    case USER_SELECTION = 'user_selection';
    case MEMBERSHIP_EXISTING = 'membership_existing';
    case FIRST_PURCHASE_RULE = 'first_purchase_rule';
    case PURCHASE_COUNT_RULE = 'purchase_count_rule';
    case SPENDING_AMOUNT_RULE = 'spending_amount_rule';
    case TIER_STATUS_RULE = 'tier_status_rule';
    case USER_SEGMENT_RULE = 'user_segment_rule';
    case DEFAULT_PROGRAM = 'default_program';
    case REFERRAL_SOURCE = 'referral_source';
    case CUSTOM_RULE = 'custom_rule';
}