<?php

declare(strict_types=1);

namespace Kanvas\Souk\Affiliates\Enums;

enum AffiliateLinkTrackingMethodEnum: string
{
    case UTM_PARAMS = 'utm_params';
    case CUSTOM_SLUG = 'custom_slug';
    case SUBDOMAIN = 'subdomain';
    case SHORT_CODE = 'short_code';
    case API = 'api';
    case COOKIE = 'cookie';
}
