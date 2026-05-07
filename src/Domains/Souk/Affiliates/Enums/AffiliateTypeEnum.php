<?php

declare(strict_types=1);

namespace Kanvas\Souk\Affiliates\Enums;

enum AffiliateTypeEnum: string
{
    case INDIVIDUAL = 'individual';
    case BUSINESS = 'business';
    case INFLUENCER = 'influencer';
    case AGENCY = 'agency';
}
