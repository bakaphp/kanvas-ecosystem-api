<?php

declare(strict_types=1);

namespace Kanvas\Souk\Affiliates\Enums;

enum AffiliateLinkTypeEnum: string
{
    case GENERAL = 'general';
    case PRODUCT = 'product';
    case CATEGORY = 'category';
    case COLLECTION = 'collection';
    case CONTENT = 'content';
    case CUSTOM_URL = 'custom_url';
    case CHECKOUT_PRESET = 'checkout_preset';
}
