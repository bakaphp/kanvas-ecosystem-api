<?php

declare(strict_types=1);

namespace App\Console\Commands\Connectors\WooCommerce;

use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\WooCommerce\Actions\PullWooCommerceUsersAction;
use Kanvas\Connectors\WooCommerce\Enums\ConfigurationEnum;

class PullWooCommerceUsersCommand extends Command
{
    protected $signature = 'kanvas:pull-woocomerce-users {app_id}';

    protected $description = 'Pull users from WooCommerce';

    public function handle()
    {
        $app = Apps::getById((int) $this->argument('app_id'));

        $wooCommerceUrl = $app->get(ConfigurationEnum::WORDPRESS_URL->value);
        if (!$wooCommerceUrl) {
            $ask = $this->ask('What is the WooCommerce Base URL?');
            $app->set(ConfigurationEnum::WORDPRESS_URL->value, $ask);
        }

        $wooCommerceUser = $app->get(ConfigurationEnum::WORDPRESS_USER->value);
        if (!$wooCommerceUser) {
            $ask = $this->ask('What is the WooCommerce User?');
            $app->set(ConfigurationEnum::WORDPRESS_USER->value, $ask);
        }

        $wooCommercePassword = $app->get(ConfigurationEnum::WORDPRESS_PASSWORD->value);
        if (!$wooCommercePassword) {
            $ask = $this->secret('What is the WooCommerce Password?');
            $app->set(ConfigurationEnum::WORDPRESS_PASSWORD->value, $ask);
        }

        $pullWooCommerceUsers = new PullWooCommerceUsersAction($app);
        $pullWooCommerceUsers->execute();

        $this->info('Users downloaded successfully from WooCommerce');
    }
}
