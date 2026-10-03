<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents\Concerns;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Intelligence\Sessions\Models\Session;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Users\Models\Users;

/**
 * Fixtures for shopper-facing agent and tool tests: a People row, orders on `commerce`, and the unsaved
 * People-keyed Session that stands in for an identified shopper (Session::people() needs no row).
 */
trait CreatesShopperFixtures
{
    protected Apps $apps;
    private Users $user;
    private Companies $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->apps = app(Apps::class);
        $this->user = auth()->user();
        $this->company = $this->user->getCurrentCompany();
    }

    private function sessionFor(People $shopper): Session
    {
        return new Session([
            'entity_namespace' => People::class,
            'entity_id' => $shopper->getId(),
        ]);
    }

    private function makeShopper(): People
    {
        return People::factory()
            ->withAppId($this->apps->getId())
            ->withCompanyId($this->company->getId())
            ->withUserId($this->user->getId())
            ->create(['firstname' => 'Shopper' . uniqid(), 'lastname' => 'Doe']);
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private function createOrder(array $attributes = []): Order
    {
        return Order::factory()
            ->withAppId($this->apps->getId())
            ->withCompanyId($this->company->getId())
            ->withUserId($this->user->getId())
            ->create(array_merge([
                'order_number' => crc32(uniqid('ord', true)),
                'is_deleted' => 0,
            ], $attributes));
    }
}
