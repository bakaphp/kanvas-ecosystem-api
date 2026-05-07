<?php

declare(strict_types=1);

namespace Kanvas\Souk\Affiliates\Enums;

enum AffiliateConversionStatusEnum: string
{
    case PENDING = 'pending';
    case CONFIRMED = 'confirmed';
    case PAID = 'paid';
    case REVERSED = 'reversed';
    case DISPUTED = 'disputed';
}
