<?php

declare(strict_types=1);

namespace Kanvas\Subscription\Enums;

use Baka\Contracts\EnumsInterface;

enum SubscriptionEnum implements EnumsInterface
{
    case TRIAL_DAYS;

    /**
     * Get value.
     */
    public function getValue(): mixed
    {
        return match ($this) {
            self::TRIAL_DAYS => 'free_trial_days',
        };
    }
}
