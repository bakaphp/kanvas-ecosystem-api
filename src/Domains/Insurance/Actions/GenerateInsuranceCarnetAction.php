<?php

declare(strict_types=1);

namespace Kanvas\Insurance\Actions;

use Kanvas\Filesystem\Models\Filesystem;
use Kanvas\Souk\Orders\Actions\GenerateOrderReceiptAction;
use Kanvas\Souk\Orders\Models\Order;

class GenerateInsuranceCarnetAction
{
    public function __construct(
        private readonly Order $order,
    ) {
    }

    public function execute(): Filesystem
    {
        return new GenerateOrderReceiptAction(
            order: $this->order,
            user: $this->order->user,
            data: new BuildInsuranceCarnetDataAction($this->order)->execute(),
        )->execute();
    }
}
