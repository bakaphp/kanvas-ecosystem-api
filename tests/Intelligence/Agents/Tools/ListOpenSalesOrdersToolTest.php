<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents\Tools;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Auth;
use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Intelligence\Agents\Neuron\Tools\Sales\ListOpenSalesOrdersTool;
use Kanvas\Souk\Orders\Models\Order;
use Tests\TestCase;

final class ListOpenSalesOrdersToolTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'commerce', 'crm'];

    protected Apps $apps;
    protected $user;
    protected $company;

    public function setUp(): void
    {
        parent::setUp();
        $this->apps = app(Apps::class);
        $this->user = Auth::user();
        $this->company = $this->user->getCurrentCompany();
    }

    public function testFilterByCustomerNameResolvesAcrossDatabaseBoundary(): void
    {
        // People lives on `crm`, Order on `commerce`. A cross-database whereHas used to emit
        // `kanvas_commerce.peoples` and blow up (Sentry KANVAS-ECOSYSTEM-64P). This asserts the
        // customer-name filter resolves people ids on their own connection instead.
        $firstname = 'Fraylin' . uniqid();
        $people = People::factory()
            ->withAppId($this->apps->getId())
            ->withCompanyId($this->company->getId())
            ->withUserId($this->user->getId())
            ->create(['firstname' => $firstname, 'lastname' => 'Gonzalez']);

        $order = $this->createOrder(['people_id' => $people->getId(), 'status' => 'pending']);

        $result = new ListOpenSalesOrdersTool()
            ->withContext($this->apps, $this->company, $this->user)
            ->__invoke(customer: $firstname);

        $orderNumbers = array_column($result['orders'], 'order_number');
        $this->assertContains($order->order_number, $orderNumbers);
    }

    public function testFilterByUnknownCustomerReturnsNoOrders(): void
    {
        $this->createOrder(['status' => 'pending']);

        $result = new ListOpenSalesOrdersTool()
            ->withContext($this->apps, $this->company, $this->user)
            ->__invoke(customer: 'NoSuchCustomer' . uniqid());

        $this->assertSame(0, $result['count']);
        $this->assertSame([], $result['orders']);
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
