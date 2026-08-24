<?php

declare(strict_types=1);

namespace Tests\Souk\Orders;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Intelligence\Agents\Laravel\Tools\Souk\ListOrderTypesTool;
use Kanvas\Intelligence\Agents\Laravel\Tools\Souk\OrderBreakdownTool;
use Kanvas\Intelligence\Agents\Laravel\Tools\Souk\OrderCommissionStatsTool;
use Kanvas\Intelligence\Agents\Laravel\Tools\Souk\OrderFulfillmentStatsTool;
use Kanvas\Intelligence\Agents\Laravel\Tools\Souk\OrderPaymentStatsTool;
use Kanvas\Intelligence\Agents\Laravel\Tools\Souk\OrderProviderStatsTool;
use Kanvas\Intelligence\Agents\Laravel\Tools\Souk\OrderTrendTool;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Orders\Models\OrderTypes;
use Kanvas\Souk\Payments\Models\Payments;
use Laravel\Ai\Tools\Request;
use Tests\TestCase;

class LaravelOrderReportToolsTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'commerce', 'crm'];

    /**
     * Same fixture as the Neuron OrderReportToolsTest: two order types + three orders on one company,
     * one card payment on the commissioned order.
     *
     * @return array{Apps, \Kanvas\Companies\Models\Companies}
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

        $payment = new Payments();
        $payment->apps_id = $app->getId();
        $payment->companies_id = $company->getId();
        $payment->users_id = $user->getId();
        $payment->payment_methods_id = 1;
        $payment->payable_id = $orderA->getId();
        $payment->payable_type = Order::class;
        $payment->payment_date = now()->toDateString();
        $payment->payment_method = 'card';
        $payment->amount = 100.0;
        $payment->currency = 'USD';
        $payment->status = 'paid';
        $payment->is_deleted = false;
        $payment->saveOrFail();

        return [$app, $company];
    }

    /**
     * @param array<string, mixed> $args
     *
     * @return array<string, mixed>
     */
    private function invokeTool(object $tool, array $args = []): array
    {
        return json_decode((string) $tool->handle(new Request($args)), true);
    }

    public function test_list_order_types_returns_tenant_types(): void
    {
        [$app, $company] = $this->seedOrders();

        $result = $this->invokeTool(new ListOrderTypesTool()->withContext($app, $company));

        $names = collect($result['order_types'])->pluck('name');
        $this->assertContains('movipass', $names);
        $this->assertContains('paso_rapido', $names);
    }

    public function test_order_breakdown_by_type_counts_orders_per_type(): void
    {
        [$app, $company] = $this->seedOrders();

        $result = $this->invokeTool(
            new OrderBreakdownTool()->withContext($app, $company),
            ['group_by' => 'type']
        );

        $byType = collect($result['groups'])->keyBy('order_type');
        $this->assertSame(2, (int) $byType['movipass']['orders']);
        $this->assertSame(1, (int) $byType['paso_rapido']['orders']);
    }

    public function test_order_payment_stats_totals_and_card_split(): void
    {
        [$app, $company] = $this->seedOrders();

        $result = $this->invokeTool(new OrderPaymentStatsTool()->withContext($app, $company));

        $this->assertSame(2, (int) $result['orders']);
        $this->assertSame(150.0, (float) $result['total_amount']);
        $this->assertSame(1, (int) $result['by_payment_method']['card']['orders']);
        $this->assertSame(100.0, (float) $result['by_payment_method']['card']['amount']);
        $this->assertSame(50.0, (float) $result['by_payment_method']['other']['amount']);
    }

    public function test_order_commission_stats_only_counts_commissioned_orders(): void
    {
        [$app, $company] = $this->seedOrders();

        $result = $this->invokeTool(new OrderCommissionStatsTool()->withContext($app, $company));

        $this->assertSame(1, (int) $result['order_count']);
        $this->assertSame(10.0, (float) $result['total_commission']);
        $this->assertSame(90.0, (float) $result['total_provider_amount']);
    }

    public function test_order_trend_returns_a_period_series(): void
    {
        [$app, $company] = $this->seedOrders();

        $result = $this->invokeTool(
            new OrderTrendTool()->withContext($app, $company),
            ['group_by' => 'month', 'order_types' => ['movipass', 'paso_rapido']]
        );

        $this->assertSame('month', $result['group_by']);
        $this->assertSame(3, (int) $result['total_orders']);
        $this->assertSame(
            now()->startOfMonth()->toDateString(),
            $result['series'][count($result['series']) - 1]['period']
        );
    }

    public function test_order_fulfillment_stats_reports_the_paid_backlog(): void
    {
        [$app, $company] = $this->seedOrders();

        $result = $this->invokeTool(
            new OrderFulfillmentStatsTool()->withContext($app, $company),
            ['order_types' => ['movipass', 'paso_rapido']]
        );

        $this->assertSame(3, (int) $result['total_orders']);
        $this->assertSame(2, (int) $result['backlog']['paid_not_fulfilled']['orders']);
        $this->assertSame(150.0, (float) $result['backlog']['paid_not_fulfilled']['amount']);
    }

    public function test_order_provider_stats_counts_orders_without_a_provider(): void
    {
        [$app, $company] = $this->seedOrders();

        $result = $this->invokeTool(
            new OrderProviderStatsTool()->withContext($app, $company),
            ['order_types' => ['movipass', 'paso_rapido']]
        );

        $this->assertSame([], $result['providers']);
        $this->assertSame(3, (int) $result['orders_without_provider']);
    }
}
