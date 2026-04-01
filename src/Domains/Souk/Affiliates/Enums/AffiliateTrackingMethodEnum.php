<?php

declare(strict_types=1);

namespace Kanvas\Souk\Affiliates\Enums;

enum AffiliateTrackingMethodEnum: string
{
    case UTM_PARAMS = 'utm_params';
    case SUBDOMAIN = 'subdomain';
    case DIRECT_LINK = 'direct_link';
    case COOKIES = 'cookies';
    case SHORT_CODES = 'short_codes';
}
