<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\IPlus;

use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\IPlus\Actions\SaveOrderToIPlusAction;
use Kanvas\Connectors\IPlus\Enums\ConfigurationEnum;
use Kanvas\Souk\Orders\Models\Order;
use Tests\TestCase;

final class OrderTest extends TestCase
{
    public function testCreatePeople()
    {
        /*         $app = app(Apps::class);
                $app->set(ConfigurationEnum::AUTH_BASE_URL->value, '');
                $app->set(ConfigurationEnum::CLIENT_ID->value, '');
                $app->set(ConfigurationEnum::CLIENT_SECRET->value, '');
                $app->set(ConfigurationEnum::USERNAME->value, '');
                $app->set(ConfigurationEnum::PASSWORD->value, '');

                $order = Order::fromApp($app)->first();
                $order->company->set(ConfigurationEnum::COMPANY_ID->value, '01');

                $createOrderInIplusAction = new SaveOrderToIPlusAction($order);
                print_R($createOrderInIplusAction->execute());
                die(); */
    }
}
