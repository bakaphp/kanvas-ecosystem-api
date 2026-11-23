<?php

declare(strict_types=1);

namespace Tests\Souk\Concerns;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Guild\Customers\Models\Address;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Locations\Models\Countries;
use Kanvas\Souk\Orders\Actions\SetupDeliveryOrderTypeAction;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Orders\Models\OrderStatus;

trait CreatesDeliveryOrder
{
    private array $deliveryOrderStatuses = [];

    protected function setUpDeliveryOrderStatuses(): void
    {
        $result = new SetupDeliveryOrderTypeAction(app(Apps::class))->execute();

        foreach ($result['statuses'] as $status) {
            $this->deliveryOrderStatuses[$status->slug] = $status;
        }
    }

    protected function deliveryOrder(Companies $company, string $statusSlug, array $overrides = [], bool $withAddress = true): Order
    {
        $status = OrderStatus::find($this->deliveryOrderStatuses[$statusSlug]->getId());
        $people = People::factory()->create([
            'apps_id' => app(Apps::class)->getId(),
            'companies_id' => $company->getId(),
            'name' => 'Maria Perez',
        ]);

        $address = $withAddress ? Address::create([
            'peoples_id' => $people->getId(),
            'address' => 'Calle El Sol 12',
            'city' => 'Santiago',
            'state' => 'Santiago',
            'zip' => '51000',
            'countries_id' => Countries::firstOrCreate(['code' => 'do'], ['name' => 'Dominican Republic'])->getId(),
        ]) : null;

        return Order::factory()
            ->withCompanyId($company->getId())
            ->withUserId((int) $company->users_id)
            ->create([
                'people_id' => $people->getId(),
                'order_types_id' => $status->order_types_id,
                'order_status_id' => $status->getId(),
                'shipping_address_id' => $address?->getId(),
                'user_email' => 'maria@customer.test',
                'user_phone' => '+18095550123',
                ...$overrides,
            ])->refresh();
    }

    protected function statusSlugOf(Order $order): string
    {
        return $order->refresh()->orderStatus->slug;
    }
}
