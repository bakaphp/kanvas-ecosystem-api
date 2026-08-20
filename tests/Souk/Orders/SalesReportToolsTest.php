<?php

declare(strict_types=1);

namespace Tests\Souk\Orders;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Intelligence\Agents\Neuron\Tools\Sales\SalesByCustomerTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Sales\SalesByProductTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Sales\SalesRevenueTool;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Orders\Models\OrderItem;
use Tests\TestCase;

class SalesReportToolsTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'commerce', 'crm'];

    private function bookedOrder(): array
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $people = People::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->withUserId($user->getId())
            ->create(['firstname' => 'Acme', 'lastname' => 'Buyer']);

        $order = Order::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->withUserId($user->getId())
            ->withPeopleId($people->getId())
            ->create(['status' => 'completed', 'total_gross_amount' => 1000.0]);

        $item = new OrderItem();
        $item->apps_id = $app->getId();
        $item->order_id = $order->id;
        $item->variant_id = 1;
        $item->variant_name = 'Kraken Elite 360';
        $item->product_name = 'Kraken Elite 360';
        $item->product_sku = 'RL-KP336';
        $item->quantity = 2;
        $item->unit_price_net_amount = 500.0;
        $item->unit_price_gross_amount = 500.0;
        $item->quantity_fulfilled = 0;
        $item->currency = 'USD';
        $item->is_public = 1;
        $item->save();

        return [$app, $company, $user, $people];
    }

    public function test_sales_by_customer_ranks_the_buyer_with_revenue(): void
    {
        [$app, $company, $user, $people] = $this->bookedOrder();

        $result = new SalesByCustomerTool()->withContext($app, $company, $user)->__invoke();

        $this->assertGreaterThanOrEqual(1, (int) $result['count']);
        $mine = collect($result['customers'])->firstWhere('customer', 'Acme Buyer');
        $this->assertNotNull($mine);
        $this->assertSame(1000.0, (float) $mine['revenue']);
    }

    public function test_sales_by_product_aggregates_units_and_revenue(): void
    {
        [$app, $company, $user] = $this->bookedOrder();

        $result = new SalesByProductTool()->withContext($app, $company, $user)->__invoke();

        $row = collect($result['products'])->firstWhere('sku', 'RL-KP336');
        $this->assertNotNull($row);
        $this->assertSame(2.0, (float) $row['units']);
        $this->assertSame(1000.0, (float) $row['revenue']);
    }

    public function test_sales_revenue_totals_and_optional_month_breakdown(): void
    {
        [$app, $company, $user] = $this->bookedOrder();

        $result = new SalesRevenueTool()->withContext($app, $company, $user)->__invoke(by_month: true);

        $this->assertGreaterThanOrEqual(1000.0, (float) $result['total_revenue']);
        $this->assertGreaterThanOrEqual(1, (int) $result['orders']);
        $this->assertNotEmpty($result['by_month']);
    }

    /**
     * A bare {total_revenue: 0, orders: 0} reads to the model as "the call failed", so it re-calls the
     * same arguments until Neuron aborts the turn with ToolRunsExceededException (KANVAS-ECOSYSTEM-682).
     */
    public function test_sales_revenue_on_an_empty_range_says_the_zero_is_final_and_shows_the_real_bounds(): void
    {
        [$app, $company, $user] = $this->bookedOrder();

        $result = new SalesRevenueTool()
            ->withContext($app, $company, $user)
            ->__invoke(since: '1999-01-01', until: '1999-01-02');

        $this->assertSame(0, (int) $result['orders']);
        $this->assertSame(0.0, (float) $result['total_revenue']);
        $this->assertSame('1999-01-01', $result['since']);
        $this->assertSame('1999-01-02', $result['until']);
        $this->assertStringContainsString('same since/until returns the same zero', $result['message']);
        $this->assertSame(
            now()->format('Y-m-d'),
            $result['last_booked_order_date'],
            'The bounds let the model correct its range in one call instead of probing dates',
        );
    }

    public function test_sales_revenue_with_results_carries_no_retry_message(): void
    {
        [$app, $company, $user] = $this->bookedOrder();

        $result = new SalesRevenueTool()->withContext($app, $company, $user)->__invoke();

        $this->assertArrayNotHasKey('message', $result);
        $this->assertSame('all-time', $result['since']);
        $this->assertSame('open-ended', $result['until']);
    }
}
