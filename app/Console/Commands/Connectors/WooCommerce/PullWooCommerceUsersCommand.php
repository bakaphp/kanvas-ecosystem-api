<?php
declare(strict_types=1);

namespace App\Console\Commands\Connectors\WooCommerce;

use Kanvas\Connectors\WooCommerce\Actions\PullWooCommerceUsersAction;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\WooCommerce\Enums\WooCommerceEnum;
use Illuminate\Console\Command;

class PullWooCommerceUsersCommand extends Command
{

    protected $signature = 'kanvas:pull-woocomerce-users {app_id}';

    protected $description = 'Pull users from WooCommerce';
    
    public function handle()
    {
        $app = Apps::getById((int) $this->argument('app_id'));

        $wooCommerceUrl = $app->get(WooCommerceEnum::WORDPRESS_URL->value);
        if (!$wooCommerceUrl) {
            $ask = $this->ask("What is the WooCommerce Base URL?");
            $app->set(WooCommerceEnum::WORDPRESS_URL->value, $ask);
        }

        $wooCommerceUser = $app->get(WooCommerceEnum::WORDPRESS_USER->value);
        if (!$wooCommerceUser) {
            $ask = $this->ask("What is the WooCommerce User?");
            $app->set(WooCommerceEnum::WORDPRESS_USER->value, $ask);
        }

        $wooCommercePassword = $app->get(WooCommerceEnum::WORDPRESS_PASSWORD->value);
        if (!$wooCommercePassword) {
            $ask = $this->secret("What is the WooCommerce Password?");
            $app->set(WooCommerceEnum::WORDPRESS_PASSWORD->value, $ask);
        }

        $pullWooCommerceUsers = new PullWooCommerceUsersAction($app);
        $pullWooCommerceUsers->execute();

        $this->info('Users downloaded successfully from WooCommerce');
    }
}
