<?php

declare(strict_types=1);

namespace Kanvas\Souk\Support;

use Baka\Contracts\AppInterface;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Orders\Models\OrderItem;
use Kanvas\SystemModules\Actions\CreateInCurrentAppAction;

class CreateSystemModule
{
    public function __construct(
        protected AppInterface $app
    ) {
    }

    public function run(): void
    {
        $createSystemModule = new CreateInCurrentAppAction($this->app);

        $createSystemModule->execute(Order::class);
        $createSystemModule->execute(OrderItem::class);
    }
}
