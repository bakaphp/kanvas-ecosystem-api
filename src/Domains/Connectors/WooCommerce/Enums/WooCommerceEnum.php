<?php

namespace Kanvas\Connectors\WooCommerce\Enums;

enum WooCommerceEnum: string
{
    case WORDPRESS_URL = 'wordpress_url';
    case WORDPRESS_USER = 'wordpress_user';
    case WORDPRESS_PASSWORD = 'wordpress_password';

    case WOOCOMMERCE_KEY = 'woocommerce_key';
    case WOOCOMMERCE_SECRET_KEY = 'woocommerce_secret_key';
}
