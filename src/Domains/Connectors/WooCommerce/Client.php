<?php

declare(strict_types=1);

namespace Kanvas\Connectors\WooCommerce;

use Automattic\WooCommerce\Client as WooCommerceClient;
use Baka\Contracts\AppInterface;
use Kanvas\Connectors\WooCommerce\Enums\ConfigurationEnum;
use Kanvas\Exceptions\ValidationException;

class Client
{
    protected WooCommerceClient $client;

    /**
     * Constructor.
     */
    public function __construct(protected AppInterface $app)
    {
        $wooCommerceUrl = $this->app->get(ConfigurationEnum::WORDPRESS_URL->value);
        $wooCommerceKey = $this->app->get(ConfigurationEnum::WOOCOMMERCE_KEY->value);
        $wooCommerceSecretKey = $this->app->get(ConfigurationEnum::WOOCOMMERCE_SECRET_KEY->value);

        if (empty($wooCommerceUrl) || empty($wooCommerceKey) || empty($wooCommerceSecretKey)) {
            throw new ValidationException('WooCommerce credentials are missing');
        }

        $this->client = new WooCommerceClient(
            $wooCommerceUrl,
            $wooCommerceKey,
            $wooCommerceSecretKey,
            [
                'version' => 'wc/v3',
            ]
        );
    }

    public function getClient(): WooCommerceClient
    {
        return $this->client;
    }
}
