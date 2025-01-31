<?php

declare(strict_types=1);

namespace App\Console\Commands\Connectors\WooCommerce;

use Kanvas\Connectors\WooCommerce\Actions\PullWooCommerceProductsAction;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\WooCommerce\Enums\WooCommerceEnum;
use Illuminate\Console\Command;
use Kanvas\Users\Models\Users;
use Kanvas\Companies\Models\Companies;
use Kanvas\Regions\Models\Regions;
use Kanvas\Connectors\WooCommerce\Actions\CreateProductAction;
use Kanvas\Connectors\WooCommerce\Services\WooCommerce;

class PullWooCommerceProductsCommand extends Command
{
    protected $signature = 'kanvas:pull-woocomerce-products {app_id} {user_id} {companies_id} {region_id} ';
    protected $description = 'Pull products from WooCommerce';

    public function handle()
    {
        $app = Apps::getById((int) $this->argument(key: 'app_id'));

        $wooCommerceUrl = $app->get(WooCommerceEnum::WORDPRESS_URL->value);
        if (! $wooCommerceUrl) {
            $ask = $this->ask("What is the WooCommerce Base URL?");
            $app->set(WooCommerceEnum::WORDPRESS_URL->value, $ask);
        }

        $wooCommerceUser = $app->get(WooCommerceEnum::WOOCOMMERCE_KEY->value);
        if (! $wooCommerceUser) {
            $ask = $this->ask("What is the WooCommerce Key?");
            $app->set(WooCommerceEnum::WOOCOMMERCE_KEY->value, $ask);
        }

        $wooCommercePassword = $app->get(WooCommerceEnum::WOOCOMMERCE_SECRET_KEY->value);
        if (! $wooCommercePassword) {
            $ask = $this->secret("What is the WooCommerce secret key?");
            $app->set(WooCommerceEnum::WOOCOMMERCE_SECRET_KEY->value, $ask);
        }

        $user = Users::getById((int) $this->argument('user_id'), $app);
        $company = Companies::getById((int) $this->argument('companies_id'), $app);
        $region = Regions::getById((int) $this->argument('region_id'), $app);
        $page = 1;
        $woocommerce = new WooCommerce($app);
        $products = $woocommerce->client->get("products", [
            'per_page' => 100,
            'page' => $page
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
            $products = $woocommerce->client->get("products", [
                'per_page' => 100,
                'page' => $page
            ]);
        }
    }
}
