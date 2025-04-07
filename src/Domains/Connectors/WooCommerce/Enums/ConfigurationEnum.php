<?php

declare(strict_types=1);

namespace Kanvas\Connectors\WooCommerce\Enums;

enum ConfigurationEnum: string
{
    case WORDPRESS_URL = 'wordpress_url';
    case WORDPRESS_USER = 'wordpress_user';
    case WORDPRESS_PASSWORD = 'wordpress_password';

    case WOOCOMMERCE_KEY = 'woocommerce_key';
    case WOOCOMMERCE_SECRET_KEY = 'woocommerce_secret_key';
}
