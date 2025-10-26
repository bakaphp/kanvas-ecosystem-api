<?php

declare(strict_types=1);

namespace Kanvas\Souk\Referrals\Enums;

enum ReferralStrategy: string
{
    case SINGLE = 'single';
    case MULTIPLE = 'multiple';
}