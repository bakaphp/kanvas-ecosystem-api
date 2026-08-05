<?php

declare(strict_types=1);

namespace Kanvas\Souk\Affiliates\Enums;

enum ConfigurationEnum: string
{
    /**
     * App setting holding a map of WooCommerce referrer affiliate slug => affiliate_links.short_code,
     * e.g. {"ua_republica_dominicana": "UA20", "ua_do": "UA20", "ua_cr": "UA20-1", ...}. Used to resolve
     * the affiliate from the order's _wc_order_attribution_referrer instead of the eSIM destination
     * country the sender writes into affiliate_shortcode.
     */
    case REFERRER_AFFILIATE_MAPPING = 'affiliate_referrer_mapping';
}
