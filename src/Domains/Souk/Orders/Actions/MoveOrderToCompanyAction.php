<?php

declare(strict_types=1);

namespace Kanvas\Souk\Orders\Actions;

use Baka\Contracts\CompanyInterface;
use Kanvas\Souk\Orders\Models\Order;

class MoveOrderToCompanyAction
{
    public function __construct(
        protected Order $order,
        protected CompanyInterface $company
    ) {
    }

    public function numberIsTaken(): bool
    {
        return Order::fromApp($this->order->app)
            ->where('companies_id', $this->company->getId())
            ->where('order_number', $this->order->order_number)
            ->where('id', '!=', $this->order->getId())
            ->exists();
    }

    public function execute(): bool
    {
        if ((int) $this->order->companies_id === $this->company->getId()) {
            return false;
        }

        $numberIsTaken = $this->numberIsTaken();

        $this->order->companies_id = $this->company->getId();

        if ($numberIsTaken) {
            $this->order->order_number = $this->order->generateOrderNumber();
        }

        $this->order->updateOrFail();

        return true;
    }
}
