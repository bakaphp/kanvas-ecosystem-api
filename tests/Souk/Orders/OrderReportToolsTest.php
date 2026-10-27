<?php

declare(strict_types=1);

namespace Tests\Souk\Orders;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Companies\Support\CompanyScope;
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

    protected array $connectionsToTransact = ['mysql', 'commerce', 'crm', 'social'];

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

        $orderB = Order::factory()
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
        $this->recordPayment($app, $company, $user, $orderB->getId(), 'cash');

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

    private function latestOrder(Companies $company, string $status): Builder
    {
        return Order::query()
            ->where('companies_id', $company->getId())
            ->where('status', $status)
            ->latest('id');
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private function backdateLatestOrder(Companies $company, string $status, array $attributes): void
    {
        $this->latestOrder($company, $status)->limit(1)->update($attributes);
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

    /**
     * KANVAS-ECOSYSTEM-6JG: a Commerce agent asked `order_payment_stats` the same question ten times in
     * one turn and the run budget killed the turn. The repeat now answers with the first result and says so.
     */
    public function test_order_payment_stats_answers_a_repeat_without_running_again(): void
    {
        [$app, $company, $user] = $this->seedOrders();
        $tool = new OrderPaymentStatsTool()->withContext($app, $company, $user);

        $first = $tool->__invoke(order_types: 'paso_rapido');
        $second = (clone $tool)->__invoke(order_types: 'paso_rapido');

        $this->assertArrayNotHasKey('repeat_call', $first);
        $this->assertTrue($second['repeat_call'], 'Neuron clones the tool per call; the guard must hold across clones');
        $this->assertSame($first['total_amount'], $second['total_amount']);
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

    public function test_order_payment_stats_counts_orders_on_their_payment_date(): void
    {
        [$app, $company, $user] = $this->seedOrders();
        $createdLongAgo = now()->subMonths(3)->startOfMonth()->addDay();

        Order::query()
            ->where('companies_id', $company->getId())
            ->where('status', 'pending')
            ->update(['created_at' => $createdLongAgo]);

        $today = new OrderPaymentStatsTool()
            ->withContext($app, $company, $user)
            ->__invoke(order_types: 'paso_rapido', since: now()->toDateString(), until: now()->toDateString());

        $creationDay = new OrderPaymentStatsTool()
            ->withContext($app, $company, $user)
            ->__invoke(order_types: 'paso_rapido', since: $createdLongAgo->toDateString(), until: $createdLongAgo->toDateString());

        $this->assertSame('payment', $today['date_anchor']);
        $this->assertSame(1, (int) $today['orders']);
        $this->assertSame(0, (int) $creationDay['orders']);
    }

    public function test_order_payment_stats_only_counts_the_agent_company(): void
    {
        [$app, $company, $user] = $this->seedOrders();

        Order::query()
            ->where('companies_id', $company->getId())
            ->where('status', 'pending')
            ->update(['companies_id' => $company->getId() + 1000000]);

        $result = new OrderPaymentStatsTool()
            ->withContext($app, $company, $user)
            ->__invoke(order_types: 'movipass,paso_rapido');

        $this->assertSame(1, (int) $result['orders']);
        $this->assertSame(100.0, (float) $result['total_amount']);
    }

    public function test_order_payment_stats_rejects_an_unknown_timezone_without_ending_the_turn(): void
    {
        [$app, $company, $user] = $this->seedOrders();

        $result = new OrderPaymentStatsTool()
            ->withContext($app, $company, $user)
            ->__invoke(timezone: 'Mars/Olympus_Mons');

        $this->assertFalse($result['success']);
        $this->assertSame('invalid_args', $result['outcome']);
        $this->assertStringContainsString('Mars/Olympus_Mons', $result['error']);
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
        $this->backdateLatestOrder($company, 'cancelled', ['created_at' => now()->subMonths(2)->startOfMonth()->addDay()]);

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

    public function test_order_trend_paid_anchor_buckets_by_payment_month(): void
    {
        [$app, $company, $user] = $this->seedOrders();

        $this->backdateLatestOrder($company, 'completed', [
            'created_at' => now()->subMonths(2)->startOfMonth()->addDay(),
            'paid_at' => now()->subMonth()->startOfMonth()->addDay(),
        ]);

        $result = new OrderTrendTool()
            ->withContext($app, $company, $user)
            ->__invoke(group_by: 'month', order_types: 'movipass,paso_rapido', date_anchor: 'paid');

        $series = collect($result['series'])->keyBy('period');
        $this->assertSame('paid', $result['date_anchor']);
        $this->assertSame(2, (int) $result['total_orders']);
        $this->assertSame(1, (int) $series[now()->subMonth()->startOfMonth()->toDateString()]['orders']);
        $this->assertSame(1, (int) $series[now()->startOfMonth()->toDateString()]['orders']);
        $this->assertArrayNotHasKey(now()->subMonths(2)->startOfMonth()->toDateString(), $series);
    }

    public function test_order_trend_cuts_days_in_the_requested_timezone(): void
    {
        [$app, $company, $user] = $this->seedOrders();
        $utcEarlyMorning = now()->utc()->startOfDay()->addHours(2);

        $this->backdateLatestOrder($company, 'cancelled', ['created_at' => $utcEarlyMorning]);

        Order::query()
            ->where('companies_id', $company->getId())
            ->where('status', 'completed')
            ->update(['created_at' => now()->subMonths(2)]);

        $localDay = $utcEarlyMorning->copy()->timezone('America/Santo_Domingo')->toDateString();

        $result = new OrderTrendTool()
            ->withContext($app, $company, $user)
            ->__invoke(
                group_by: 'day',
                order_types: 'movipass',
                since: $localDay,
                until: $localDay,
                timezone: 'America/Santo_Domingo',
            );

        $this->assertSame('America/Santo_Domingo', $result['timezone']);
        $this->assertSame(1, (int) $result['total_orders']);
        $this->assertSame($localDay, $result['series'][0]['period']);
    }

    public function test_order_trend_reports_the_highest_gross_revenue_period(): void
    {
        [$app, $company, $user] = $this->seedOrders();

        $this->backdateLatestOrder($company, 'completed', ['created_at' => now()->subMonths(2)->startOfMonth()->addDay()]);

        $result = new OrderTrendTool()
            ->withContext($app, $company, $user)
            ->__invoke(group_by: 'month', order_types: 'movipass,paso_rapido');

        $this->assertSame(now()->subMonths(2)->startOfMonth()->toDateString(), $result['peak_gross_revenue_period']['period']);
        $this->assertSame(120.0, (float) $result['peak_gross_revenue_period']['gross_revenue']);
    }

    public function test_order_trend_counts_a_customer_as_new_only_in_their_first_period(): void
    {
        [$app, $company, $user] = $this->seedOrders();

        $this->backdateLatestOrder($company, 'cancelled', ['created_at' => now()->subMonths(2)->startOfMonth()->addDay()]);

        $result = new OrderTrendTool()
            ->withContext($app, $company, $user)
            ->__invoke(group_by: 'month', order_types: 'movipass,paso_rapido');

        $series = collect($result['series'])->keyBy('period');
        $first = $series[now()->subMonths(2)->startOfMonth()->toDateString()];
        $current = $series[now()->startOfMonth()->toDateString()];

        // The cancelled, unpaid order two months back is still the person's first order: "any order" counts.
        $this->assertSame(1, $first['new_customers']);
        $this->assertSame(1, $first['new_to_company']);
        $this->assertSame(0, $current['new_customers']);
        $this->assertSame(0, $current['new_to_company']);
        $this->assertSame(1, $result['total_new_customers']);
        $this->assertSame(1, $result['total_new_to_company']);
    }

    public function test_order_trend_splits_new_to_the_vertical_from_new_to_the_company(): void
    {
        [$app, $company, $user] = $this->seedOrders();
        $pasoRapido = OrderTypes::query()->where('companies_id', $company->getId())->where('name', 'paso_rapido')->firstOrFail();

        $this->backdateLatestOrder($company, 'cancelled', ['created_at' => now()->subMonths(2)->startOfMonth()->addDay()]);

        $newcomer = People::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->withUserId($user->getId())
            ->create(['firstname' => 'First', 'lastname' => 'Timer']);

        Order::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->withUserId($user->getId())
            ->withPeopleId($newcomer->getId())
            ->create(['order_types_id' => $pasoRapido->getId(), 'status' => 'completed', 'payment_status' => 'paid']);

        Order::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->withPeopleId(0)
            ->create([
                'order_types_id' => $pasoRapido->getId(),
                'users_id' => null,
                'user_email' => null,
                'status' => 'completed',
                'payment_status' => 'paid',
            ]);

        $result = new OrderTrendTool()
            ->withContext($app, $company, $user)
            ->__invoke(group_by: 'month', order_types: 'paso_rapido');

        $this->assertCount(1, $result['series']);
        $row = $result['series'][0];

        // Toll Payer is new to paso_rapido this month but bought movipass two months ago;
        // First Timer has never ordered anything; the guest order has no customer at all.
        $this->assertSame(3, $row['orders']);
        $this->assertSame(2, $row['new_customers']);
        $this->assertSame(1, $row['new_to_company']);
        $this->assertSame(1, $row['orders_without_customer']);
        $this->assertSame(1, $result['total_orders_without_customer']);
    }

    public function test_order_trend_paid_only_ignores_unpaid_orders_when_finding_the_first_order(): void
    {
        [$app, $company, $user] = $this->seedOrders();

        $this->backdateLatestOrder($company, 'cancelled', ['created_at' => now()->subMonths(2)->startOfMonth()->addDay()]);

        $result = new OrderTrendTool()
            ->withContext($app, $company, $user)
            ->__invoke(group_by: 'month', order_types: 'movipass,paso_rapido', paid_only: true);

        $series = collect($result['series'])->keyBy('period');

        $this->assertCount(1, $series);
        $this->assertSame(1, $series[now()->startOfMonth()->toDateString()]['new_customers']);
        $this->assertSame(1, $series[now()->startOfMonth()->toDateString()]['new_to_company']);
    }

    public function test_order_trend_rejects_an_unknown_timezone_without_ending_the_turn(): void
    {
        [$app, $company, $user] = $this->seedOrders();

        $result = new OrderTrendTool()
            ->withContext($app, $company, $user)
            ->__invoke(timezone: 'Mars/Olympus_Mons');

        $this->assertFalse($result['success']);
        $this->assertSame('invalid_args', $result['outcome']);
        $this->assertStringContainsString('Mars/Olympus_Mons', $result['error']);
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

    private function tagLatestOrder(
        Companies $company,
        string $status,
        string $tag,
        Apps $app,
        mixed $user
    ): void {
        /** @var Order $order */
        $order = $this->latestOrder($company, $status)->firstOrFail();

        $order->addTag($tag, $app, $user, $company);
    }

    public function test_list_order_types_lists_the_tags_carried_by_this_companys_orders(): void
    {
        [$app, $company, $user] = $this->seedOrders();
        $this->tagLatestOrder($company, 'completed', 'Corporativo', $app, $user);

        $result = new ListOrderTypesTool()->withContext($app, $company, $user)->__invoke();

        $this->assertContains('Corporativo', $result['tags']);
    }

    public function test_order_trend_filters_by_tag_alone_and_combined_with_an_order_type(): void
    {
        [$app, $company, $user] = $this->seedOrders();
        $this->tagLatestOrder($company, 'completed', 'corporativo', $app, $user);
        $this->tagLatestOrder($company, 'pending', 'corporativo', $app, $user);

        $tagged = new OrderTrendTool()->withContext($app, $company, $user)->__invoke(tags: 'corporativo');

        $this->assertSame(2, (int) $tagged['total_orders']);
        $this->assertSame(180.0, (float) $tagged['total_gross_revenue']);
        $this->assertSame(1, (int) $tagged['total_new_customers']);
        $this->assertSame(1, (int) $tagged['total_new_to_company']);

        $taggedMovipass = new OrderTrendTool()
            ->withContext($app, $company, $user)
            ->__invoke(order_types: 'movipass', tags: 'Corporativo');

        $this->assertSame(1, (int) $taggedMovipass['total_orders']);
        $this->assertSame(120.0, (float) $taggedMovipass['total_gross_revenue']);
    }

    public function test_order_breakdown_and_payment_stats_honour_the_tag_filter(): void
    {
        [$app, $company, $user] = $this->seedOrders();
        $this->tagLatestOrder($company, 'completed', 'corporativo', $app, $user);

        $breakdown = new OrderBreakdownTool()->withContext($app, $company, $user)->__invoke(tags: 'corporativo');
        $payments = new OrderPaymentStatsTool()->withContext($app, $company, $user)->__invoke(tags: 'corporativo');

        $this->assertSame(1, (int) $breakdown['total_orders']);
        $this->assertSame(120.0, (float) $breakdown['total_revenue']);
        $this->assertSame(1, (int) $payments['orders']);
        $this->assertSame(100.0, (float) $payments['total_amount']);
    }

    public function test_order_trend_rejects_an_unknown_tag_and_names_the_tags_in_use(): void
    {
        [$app, $company, $user] = $this->seedOrders();
        $this->tagLatestOrder($company, 'completed', 'corporativo', $app, $user);

        $result = new OrderTrendTool()->withContext($app, $company, $user)->__invoke(tags: 'corporate');

        $this->assertFalse($result['success']);
        $this->assertSame('invalid_args', $result['outcome']);
        $this->assertStringContainsString('corporate', $result['error']);
        $this->assertStringContainsString('corporativo', $result['error']);
    }

    public function test_order_trend_rejects_an_unknown_order_type_and_names_the_valid_ones(): void
    {
        [$app, $company, $user] = $this->seedOrders();

        $result = new OrderTrendTool()
            ->withContext($app, $company, $user)
            ->__invoke(order_types: 'paso_rapido_corporativo');

        $this->assertFalse($result['success']);
        $this->assertSame('invalid_args', $result['outcome']);
        $this->assertStringContainsString('paso_rapido_corporativo', $result['error']);
        $this->assertStringContainsString('movipass', $result['error']);
        $this->assertStringContainsString('paso_rapido', $result['error']);
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

    public function test_orders_export_accepts_a_company_scope(): void
    {
        [$app, $company] = $this->seedOrders();
        $exporter = new OrdersRecordExporter();

        // The shared CI database holds orders other suites committed to this company, so every
        // assertion is relative to the own-company result rather than an absolute row count.
        $own = CompanyScope::ownCompanyOnly($app, $company);
        $ownRows = $exporter->rows($app, $own, ['order_type' => 'paso_rapido']);

        $this->assertNotEmpty($ownRows);
        $this->assertSame(['paso_rapido'], array_values(array_unique(array_column($ownRows, 2))));

        $other = Companies::factory()->create(['users_id' => auth()->user()->getId()]);
        $wide = CompanyScope::withCompanies($app, $company, [$other->getId()]);

        $this->assertSame($ownRows, $exporter->rows($app, $wide, ['order_type' => 'paso_rapido']));
    }
}
