<?php

declare(strict_types=1);

namespace Tests\Souk\Orders;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Intelligence\Agents\Neuron\Exporters\OrdersRecordExporter;
use Kanvas\Intelligence\Agents\Neuron\Tools\Souk\ListOrderTypesTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Souk\OrderBreakdownTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Souk\OrderCommissionStatsTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Souk\OrderFulfillmentStatsTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Souk\OrderPaymentStatsTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Souk\OrderProviderStatsTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Souk\OrderTrendTool;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Orders\Models\OrderTypes;
use Kanvas\Souk\Payments\Models\Payments;
use Tests\TestCase;

class OrderReportToolsTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'commerce', 'crm'];

    /**
     * Two order types + three orders on one company:
     *  A) movipass, completed, paid, commissioned (net 100), settled by CARD
     *  B) paso_rapido, pending, paid (net 50), settled by CASH
     *  C) movipass, cancelled, unpaid (net 30), no commission
     *
     * @return array{Apps, \Kanvas\Companies\Models\Companies, \Kanvas\Users\Models\Users}
     */
    private function seedOrders(): array
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $makeType = function (string $name) use ($app, $company): OrderTypes {
            $type = new OrderTypes();
            $type->apps_id = $app->getId();
            $type->companies_id = $company->getId();
            $type->name = $name;
            $type->saveOrFail();

            return $type;
        };
        $movipass = $makeType('movipass');
        $pasoRapido = $makeType('paso_rapido');

        $people = People::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->withUserId($user->getId())
            ->create(['firstname' => 'Toll', 'lastname' => 'Payer']);

        $orderA = Order::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->withUserId($user->getId())
            ->withPeopleId($people->getId())
            ->create([
                'order_types_id' => $movipass->getId(),
                'status' => 'completed',
                'payment_status' => 'paid',
                'total_gross_amount' => 120.0,
                'total_net_amount' => 100.0,
                'commission_rate' => 10.0,
                'commission_amount' => 10.0,
                'provider_amount' => 90.0,
            ]);

        Order::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->withUserId($user->getId())
            ->withPeopleId($people->getId())
            ->create([
                'order_types_id' => $pasoRapido->getId(),
                'status' => 'pending',
                'payment_status' => 'paid',
                'total_gross_amount' => 60.0,
                'total_net_amount' => 50.0,
            ]);

        Order::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->withUserId($user->getId())
            ->withPeopleId($people->getId())
            ->create([
                'order_types_id' => $movipass->getId(),
                'status' => 'cancelled',
                'payment_status' => 'unpaid',
                'total_gross_amount' => 30.0,
                'total_net_amount' => 30.0,
            ]);

        $this->recordPayment($app, $company, $user, $orderA->getId(), 'card');

        return [$app, $company, $user];
    }

    private function recordPayment(Apps $app, mixed $company, mixed $user, int $orderId, string $method): void
    {
        $payment = new Payments();
        $payment->apps_id = $app->getId();
        $payment->companies_id = $company->getId();
        $payment->users_id = $user->getId();
        $payment->payment_methods_id = 1;
        $payment->payable_id = $orderId;
        $payment->payable_type = Order::class;
        $payment->payment_date = now()->toDateString();
        $payment->payment_method = $method;
        $payment->amount = 100.0;
        $payment->currency = 'USD';
        $payment->status = 'paid';
        $payment->is_deleted = false;
        $payment->saveOrFail();
    }

    public function test_list_order_types_returns_tenant_types(): void
    {
        [$app, $company, $user] = $this->seedOrders();

        $result = new ListOrderTypesTool()->withContext($app, $company, $user)->__invoke();

        $names = collect($result['order_types'])->pluck('name');
        $this->assertContains('movipass', $names);
        $this->assertContains('paso_rapido', $names);
    }

    public function test_order_breakdown_by_status_buckets_every_state(): void
    {
        [$app, $company, $user] = $this->seedOrders();

        // Restrict to the seeded types so the per-status counts stay deterministic — the default test
        // company accumulates orders of other types/statuses from parallel suite runs.
        $result = new OrderBreakdownTool()
            ->withContext($app, $company, $user)
            ->__invoke(group_by: 'status', order_types: 'movipass,paso_rapido');

        $byStatus = collect($result['groups'])->keyBy('status');
        $this->assertSame(1, (int) $byStatus['completed']['orders']);
        $this->assertSame(1, (int) $byStatus['pending']['orders']);
        $this->assertSame(1, (int) $byStatus['cancelled']['orders']);
    }

    public function test_order_breakdown_by_type_counts_orders_per_type(): void
    {
        [$app, $company, $user] = $this->seedOrders();

        $result = new OrderBreakdownTool()->withContext($app, $company, $user)->__invoke(group_by: 'type');

        $byType = collect($result['groups'])->keyBy('order_type');
        $this->assertSame(2, (int) $byType['movipass']['orders']);
        $this->assertSame(1, (int) $byType['paso_rapido']['orders']);
    }

    public function test_order_payment_stats_totals_and_card_split(): void
    {
        [$app, $company, $user] = $this->seedOrders();

        $result = new OrderPaymentStatsTool()->withContext($app, $company, $user)->__invoke();

        $this->assertSame(2, (int) $result['orders']);
        $this->assertSame(150.0, (float) $result['total_amount']);
        $this->assertSame(1, (int) $result['by_payment_method']['card']['orders']);
        $this->assertSame(100.0, (float) $result['by_payment_method']['card']['amount']);
        $this->assertSame(1, (int) $result['by_payment_method']['other']['orders']);
        $this->assertSame(50.0, (float) $result['by_payment_method']['other']['amount']);
    }

    public function test_order_payment_stats_restricts_by_order_type(): void
    {
        [$app, $company, $user] = $this->seedOrders();

        $result = new OrderPaymentStatsTool()
            ->withContext($app, $company, $user)
            ->__invoke(order_types: 'paso_rapido');

        $this->assertSame(1, (int) $result['orders']);
        $this->assertSame(50.0, (float) $result['total_amount']);
    }

    public function test_order_commission_stats_only_counts_commissioned_orders(): void
    {
        [$app, $company, $user] = $this->seedOrders();

        $result = new OrderCommissionStatsTool()->withContext($app, $company, $user)->__invoke();

        $this->assertSame(1, (int) $result['order_count']);
        $this->assertSame(100.0, (float) $result['total_revenue']);
        $this->assertSame(10.0, (float) $result['total_commission']);
        $this->assertSame(90.0, (float) $result['total_provider_amount']);
    }

    public function test_order_trend_buckets_orders_per_month(): void
    {
        [$app, $company, $user] = $this->seedOrders();

        // Push one order two months back so the series has more than a single bucket.
        Order::query()
            ->where('companies_id', $company->getId())
            ->where('status', 'cancelled')
            ->latest('id')
            ->limit(1)
            ->update(['created_at' => now()->subMonths(2)->startOfMonth()->addDay()]);

        $result = new OrderTrendTool()
            ->withContext($app, $company, $user)
            ->__invoke(group_by: 'month', order_types: 'movipass,paso_rapido');

        $series = collect($result['series'])->keyBy('period');
        $this->assertSame('month', $result['group_by']);
        $this->assertSame(3, (int) $result['total_orders']);
        $this->assertCount(2, $series);
        $this->assertSame(2, (int) $series[now()->startOfMonth()->toDateString()]['orders']);
        $this->assertSame(1, (int) $series[now()->subMonths(2)->startOfMonth()->toDateString()]['orders']);
        $this->assertSame(2, (int) $result['peak_period']['orders']);
        $this->assertSame(1, (int) $result['lowest_period']['orders']);
    }

    public function test_order_trend_paid_only_drops_unpaid_orders(): void
    {
        [$app, $company, $user] = $this->seedOrders();

        $result = new OrderTrendTool()
            ->withContext($app, $company, $user)
            ->__invoke(order_types: 'movipass,paso_rapido', paid_only: true);

        $this->assertSame(2, (int) $result['total_orders']);
        $this->assertSame(150.0, (float) $result['total_net_revenue']);
    }

    public function test_order_fulfillment_stats_backlog_ignores_cancelled_orders(): void
    {
        [$app, $company, $user] = $this->seedOrders();

        $result = new OrderFulfillmentStatsTool()
            ->withContext($app, $company, $user)
            ->__invoke(order_types: 'movipass,paso_rapido');

        $byPayment = collect($result['by_payment_status'])->keyBy('payment_status');
        $this->assertSame(3, (int) $result['total_orders']);
        $this->assertSame(2, (int) $byPayment['paid']['orders']);
        $this->assertSame(150.0, (float) $byPayment['paid']['amount']);

        // Both paid orders are unfulfilled; the unpaid one is cancelled so it is out of the backlog.
        $this->assertSame(2, (int) $result['backlog']['paid_not_fulfilled']['orders']);
        $this->assertSame(150.0, (float) $result['backlog']['paid_not_fulfilled']['amount']);
        $this->assertSame(0, (int) $result['backlog']['unpaid']['orders']);
    }

    public function test_order_provider_stats_splits_revenue_per_provider(): void
    {
        [$app, $company, $user] = $this->seedOrders();

        $provider = Companies::factory()->create(['users_id' => $user->getId()]);
        $commissioned = Order::query()
            ->where('companies_id', $company->getId())
            ->where('status', 'completed')
            ->latest('id')
            ->firstOrFail();
        $commissioned->providerCompanies()->attach($provider->getId());

        $result = new OrderProviderStatsTool()
            ->withContext($app, $company, $user)
            ->__invoke(order_types: 'movipass,paso_rapido');

        $row = collect($result['providers'])->firstWhere('company_id', $provider->getId());
        $this->assertNotNull($row);
        $this->assertSame(1, (int) $row['orders']);
        $this->assertSame(100.0, (float) $row['net_revenue']);
        $this->assertSame(10.0, (float) $row['commission']);
        $this->assertSame(90.0, (float) $row['provider_payout']);
        $this->assertSame(2, (int) $result['orders_without_provider']);
    }

    public function test_orders_export_filters_by_order_type(): void
    {
        [$app, $company, $user] = $this->seedOrders();

        $rows = new OrdersRecordExporter()->rows($app, $company, ['order_type' => 'paso_rapido']);

        $this->assertNotEmpty($rows);
        // Order Type and Payment Status columns sit at index 2 and 4.
        $this->assertSame(['paso_rapido'], array_values(array_unique(array_column($rows, 2))));
        $this->assertSame('paid', $rows[0][4]);
    }
}
