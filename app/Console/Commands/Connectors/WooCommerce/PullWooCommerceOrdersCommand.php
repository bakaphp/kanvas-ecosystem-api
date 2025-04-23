<?php

declare(strict_types=1);

namespace App\Console\Commands\Connectors\WooCommerce;

use Baka\Traits\KanvasJobsTrait;
use Exception;
use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\WooCommerce\Actions\CreateOrderAction;
use Kanvas\Connectors\WooCommerce\Enums\ConfigurationEnum;
use Kanvas\Connectors\WooCommerce\Services\WooCommerce;
use Kanvas\Regions\Models\Regions;
use Kanvas\Users\Models\Users;

class PullWooCommerceOrdersCommand extends Command
{
    use KanvasJobsTrait;

    protected $signature = 'kanvas:pull-woocomerce-orders {app_id} {user_id} {companies_id} {region_id} ';
    protected $description = 'Pull orders from WooCommerce';

    public function handle()
    {
        $app = Apps::getById((int) $this->argument(key: 'app_id'));
        $this->overwriteAppService($app);

        $wooCommerceUrl = $app->get(ConfigurationEnum::WORDPRESS_URL->value);
        if (! $wooCommerceUrl) {
            $ask = $this->ask('What is the WooCommerce Base URL?');
            $app->set(ConfigurationEnum::WORDPRESS_URL->value, $ask);
        }

        $wooCommerceUser = $app->get(ConfigurationEnum::WOOCOMMERCE_KEY->value);
        if (! $wooCommerceUser) {
            $ask = $this->ask('What is the WooCommerce Key?');
            $app->set(ConfigurationEnum::WOOCOMMERCE_KEY->value, $ask);
        }

        $wooCommercePassword = $app->get(ConfigurationEnum::WOOCOMMERCE_SECRET_KEY->value);
        if (! $wooCommercePassword) {
            $ask = $this->secret('What is the WooCommerce secret key?');
            $app->set(ConfigurationEnum::WOOCOMMERCE_SECRET_KEY->value, $ask);
        }

        $user = Users::getById((int) $this->argument('user_id'), $app);
        $company = Companies::getById((int) $this->argument('companies_id'), $app);
        $region = Regions::getById((int) $this->argument('region_id'), $app);

        $wooCommerce = new WooCommerce($app);
        $page = 1;
        $orders = $wooCommerce->client->get('orders', [
            'status'   => 'completed',
            'per_page' => 100,
            'page'     => $page,
        ]);
        $totalPage = $wooCommerce->client->http->getResponse()->getHeaders()['X-WP-TotalPages'][0] ?? 1;
        while ($page <= $totalPage) {
            foreach ($orders as $order) {
                try {
                    (new CreateOrderAction(
                        $app,
                        $company,
                        $user,
                        $region,
                        $order
                    ))->execute();
                } catch (Exception $e) {
                    echo $e->getMessage().PHP_EOL;
                    echo $e->getTraceAsString().PHP_EOL;

                    break;
                }
            }
            $page++;
            $orders = $wooCommerce->client->get('orders', [
                'status'   => 'completed',
                'per_page' => 100,
                'page'     => $page,
            ]);
        }
    }
}
