<?php

declare(strict_types=1);

namespace App\Console\Commands\Connectors\WooCommerce;

use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\WooCommerce\Actions\CreateProductAction;
use Kanvas\Connectors\WooCommerce\Enums\ConfigurationEnum;
use Kanvas\Connectors\WooCommerce\Services\WooCommerce;
use Kanvas\Regions\Models\Regions;
use Kanvas\Users\Models\Users;

class PullWooCommerceProductsCommand extends Command
{
    protected $signature = 'kanvas:pull-woocomerce-products {app_id} {user_id} {companies_id} {region_id} ';
    protected $description = 'Pull products from WooCommerce';

    public function handle()
    {
        $app = Apps::getById((int) $this->argument(key: 'app_id'));

        $wooCommerceUrl = $app->get(ConfigurationEnum::WORDPRESS_URL->value);
        if (!$wooCommerceUrl) {
            $ask = $this->ask('What is the WooCommerce Base URL?');
            $app->set(ConfigurationEnum::WORDPRESS_URL->value, $ask);
        }

        $wooCommerceUser = $app->get(ConfigurationEnum::WOOCOMMERCE_KEY->value);
        if (!$wooCommerceUser) {
            $ask = $this->ask('What is the WooCommerce Key?');
            $app->set(ConfigurationEnum::WOOCOMMERCE_KEY->value, $ask);
        }

        $wooCommercePassword = $app->get(ConfigurationEnum::WOOCOMMERCE_SECRET_KEY->value);
        if (!$wooCommercePassword) {
            $ask = $this->secret('What is the WooCommerce secret key?');
            $app->set(ConfigurationEnum::WOOCOMMERCE_SECRET_KEY->value, $ask);
        }

        $user = Users::getById((int) $this->argument('user_id'), $app);
        $company = Companies::getById((int) $this->argument('companies_id'), $app);
        $region = Regions::getById((int) $this->argument('region_id'), $app);
        $page = 1;
        $woocommerce = new WooCommerce($app);
        $products = $woocommerce->client->get('products', [
            'per_page' => 100,
            'page'     => $page,
            'status'   => 'publish',
        ]);
        $totalPage = $woocommerce->client->http->getResponse()->getHeaders()['X-WP-TotalPages'][0] ?? 1;
        while ($page <= $totalPage) {
            foreach ($products as $product) {
                (new CreateProductAction(
                    $app,
                    $company,
                    $user,
                    $region,
                    $product
                ))->execute();
            }
            $page++;
            $products = $woocommerce->client->get('products', [
                'per_page' => 100,
                'page'     => $page,
            ]);
        }
    }
}
