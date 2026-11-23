<?php

declare(strict_types=1);

namespace Tests\Souk\Orders;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use InvalidArgumentException;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Intelligence\Agents\Laravel\Tools\Souk\ActiveUsersTool as LaravelActiveUsersTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Souk\ActiveUsersTool;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Orders\Models\OrderTypes;
use Kanvas\Souk\Orders\Services\OrderReportService;
use Kanvas\Souk\Payments\Models\Payments;
use Kanvas\Users\Models\Users;
use Laravel\Ai\Tools\Request;
use NeuronAI\Tools\PropertyType;
use Tests\TestCase;

class ActiveUsersReportTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'commerce', 'crm', 'social'];

    private const string SINCE = '2026-01-01';
    private const string UNTIL = '2026-03-31';

    private function makeUser(): Users
    {
        return Users::factory()->create(['email' => 'active-' . uniqid() . '@example.test']);
    }

    private function makeType(Apps $app, Companies $company, string $prefix): OrderTypes
    {
        $type = new OrderTypes();
        $type->apps_id = $app->getId();
        $type->companies_id = $company->getId();
        $type->name = $prefix . '_' . uniqid();
        $type->saveOrFail();

        return $type;
    }

    private function makeOrder(Apps $app, Companies $company, OrderTypes $type, Users $creator): Order
    {
        $people = People::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->withUserId($creator->getId())
            ->create();

        return Order::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->withUserId($creator->getId())
            ->withPeopleId($people->getId())
            ->create([
                'order_types_id' => $type->getId(),
                'status' => 'completed',
                'payment_status' => 'paid',
            ]);
    }

    private function pay(
        Apps $app,
        Companies $company,
        Order $order,
        Users $payer,
        string $createdAt,
        string $status = 'paid'
    ): Payments {
        $payment = new Payments();
        $payment->apps_id = $app->getId();
        $payment->companies_id = $company->getId();
        $payment->users_id = $payer->getId();
        $payment->payment_methods_id = 1;
        $payment->payable_id = $order->getId();
        $payment->payable_type = Order::class;
        $payment->payment_date = substr($createdAt, 0, 10);
        $payment->payment_method = 'card';
        $payment->amount = 100.0;
        $payment->currency = 'USD';
        $payment->status = $status;
        $payment->is_deleted = false;
        $payment->created_at = $createdAt;
        $payment->saveOrFail();

        return $payment;
    }

    /**
     * @return array{Apps, Companies, Users}
     */
    private function context(): array
    {
        $user = auth()->user();

        return [app(Apps::class), $user->getCurrentCompany(), $user];
    }

    /**
     * @param array<string, mixed> $result
     * @return array<string, array<string, mixed>>
     */
    private function verticals(array $result): array
    {
        return collect($result['by_vertical'])->keyBy('order_type')->all();
    }

    public function test_total_is_deduplicated_while_each_vertical_counts_its_own_users(): void
    {
        [$app, $company, $creator] = $this->context();
        $typeA = $this->makeType($app, $company, 'vertical_a');
        $typeB = $this->makeType($app, $company, 'vertical_b');
        $shared = $this->makeUser();
        $onlyB = $this->makeUser();

        $this->pay($app, $company, $this->makeOrder($app, $company, $typeA, $creator), $shared, '2026-01-10 15:00:00');
        $this->pay($app, $company, $this->makeOrder($app, $company, $typeA, $creator), $shared, '2026-01-11 15:00:00');
        $this->pay($app, $company, $this->makeOrder($app, $company, $typeB, $creator), $shared, '2026-02-10 15:00:00');
        $this->pay($app, $company, $this->makeOrder($app, $company, $typeB, $creator), $onlyB, '2026-02-11 15:00:00');

        $result = new OrderReportService($app, $company)->activeUsers(
            orderTypeNames: [$typeA->name, $typeB->name],
            since: self::SINCE,
            until: self::UNTIL,
            timezone: 'UTC',
            groupBy: null,
        );

        $byVertical = $this->verticals($result);
        $this->assertSame(2, $result['total_active_users']);
        $this->assertSame(4, $result['total_paid_orders']);
        $this->assertSame(2.0, $result['orders_per_user']);
        $this->assertSame(1, $byVertical[$typeA->name]['active_users']);
        $this->assertSame(2, $byVertical[$typeA->name]['paid_orders']);
        $this->assertSame(2.0, $byVertical[$typeA->name]['orders_per_user']);
        $this->assertSame(2, $byVertical[$typeB->name]['active_users']);
        $this->assertSame(2, $byVertical[$typeB->name]['paid_orders']);
        $this->assertSame(3, $result['months_in_range']);
        $this->assertSame([], $result['series']);
    }

    public function test_only_paid_payments_make_a_user_active(): void
    {
        [$app, $company, $creator] = $this->context();
        $type = $this->makeType($app, $company, 'paid_only');
        $payer = $this->makeUser();
        $failed = $this->makeUser();
        $pending = $this->makeUser();

        $this->pay($app, $company, $this->makeOrder($app, $company, $type, $creator), $payer, '2026-01-10 15:00:00');
        $this->pay($app, $company, $this->makeOrder($app, $company, $type, $creator), $failed, '2026-01-10 15:00:00', 'failed');
        $this->pay($app, $company, $this->makeOrder($app, $company, $type, $creator), $pending, '2026-01-10 15:00:00', 'pending');

        $result = new OrderReportService($app, $company)->activeUsers(
            orderTypeNames: [$type->name],
            since: self::SINCE,
            until: self::UNTIL,
            timezone: 'UTC',
            groupBy: null,
        );

        $this->assertSame(1, $result['total_active_users']);
        $this->assertSame(1, $result['total_paid_orders']);
    }

    public function test_the_payer_is_counted_not_the_order_creator(): void
    {
        [$app, $company, $creator] = $this->context();
        $type = $this->makeType($app, $company, 'payer');
        $customerOne = $this->makeUser();
        $customerTwo = $this->makeUser();

        $this->pay($app, $company, $this->makeOrder($app, $company, $type, $creator), $customerOne, '2026-01-10 15:00:00');
        $this->pay($app, $company, $this->makeOrder($app, $company, $type, $creator), $customerTwo, '2026-01-12 15:00:00');

        $result = new OrderReportService($app, $company)->activeUsers(
            orderTypeNames: [$type->name],
            since: self::SINCE,
            until: self::UNTIL,
            timezone: 'UTC',
            groupBy: null,
        );

        $this->assertSame(2, $result['total_active_users']);
    }

    public function test_deleted_orders_and_payments_outside_the_range_are_excluded(): void
    {
        [$app, $company, $creator] = $this->context();
        $type = $this->makeType($app, $company, 'range');
        $inRange = $this->makeUser();
        $before = $this->makeUser();
        $after = $this->makeUser();
        $deletedOrderPayer = $this->makeUser();

        $this->pay($app, $company, $this->makeOrder($app, $company, $type, $creator), $inRange, '2026-02-01 15:00:00');
        $this->pay($app, $company, $this->makeOrder($app, $company, $type, $creator), $before, '2025-12-31 15:00:00');
        $this->pay($app, $company, $this->makeOrder($app, $company, $type, $creator), $after, '2026-04-01 15:00:00');
        $deletedOrder = $this->makeOrder($app, $company, $type, $creator);
        $this->pay($app, $company, $deletedOrder, $deletedOrderPayer, '2026-02-01 15:00:00');
        $deletedOrder->is_deleted = true;
        $deletedOrder->saveOrFail();

        $result = new OrderReportService($app, $company)->activeUsers(
            orderTypeNames: [$type->name],
            since: self::SINCE,
            until: self::UNTIL,
            timezone: 'UTC',
            groupBy: null,
        );

        $this->assertSame(1, $result['total_active_users']);
        $this->assertSame(1, $result['total_paid_orders']);
    }

    public function test_the_timezone_decides_which_day_a_payment_falls_on(): void
    {
        [$app, $company, $creator] = $this->context();
        $type = $this->makeType($app, $company, 'tz');
        $payer = $this->makeUser();

        $this->pay($app, $company, $this->makeOrder($app, $company, $type, $creator), $payer, '2026-02-02 02:00:00');

        $service = new OrderReportService($app, $company);
        $utc = $service->activeUsers(
            orderTypeNames: [$type->name],
            since: '2026-02-02',
            until: '2026-02-02',
            timezone: 'UTC',
            groupBy: null,
        );
        $santoDomingo = $service->activeUsers(
            orderTypeNames: [$type->name],
            since: '2026-02-02',
            until: '2026-02-02',
            timezone: 'America/Santo_Domingo',
            groupBy: null,
        );
        $previousLocalDay = $service->activeUsers(
            orderTypeNames: [$type->name],
            since: '2026-02-01',
            until: '2026-02-01',
            timezone: 'America/Santo_Domingo',
            groupBy: null,
        );

        $this->assertSame(1, $utc['total_active_users']);
        $this->assertSame(0, $santoDomingo['total_active_users']);
        $this->assertSame(1, $previousLocalDay['total_active_users']);
    }

    public function test_group_by_month_returns_a_series_in_local_time(): void
    {
        [$app, $company, $creator] = $this->context();
        $type = $this->makeType($app, $company, 'series');
        $payer = $this->makeUser();
        $other = $this->makeUser();

        $this->pay($app, $company, $this->makeOrder($app, $company, $type, $creator), $payer, '2026-01-10 15:00:00');
        $this->pay($app, $company, $this->makeOrder($app, $company, $type, $creator), $other, '2026-01-20 15:00:00');
        $this->pay($app, $company, $this->makeOrder($app, $company, $type, $creator), $payer, '2026-03-05 15:00:00');

        $result = new OrderReportService($app, $company)->activeUsers(
            orderTypeNames: [$type->name],
            since: self::SINCE,
            until: self::UNTIL,
            timezone: 'UTC',
            groupBy: 'month',
        );

        $series = collect($result['series'])->keyBy('period');
        $this->assertSame('month', $result['group_by']);
        $this->assertCount(2, $series);
        $this->assertSame(2, $series['2026-01-01']['active_users']);
        $this->assertSame(2, $series['2026-01-01']['paid_orders']);
        $this->assertSame(1, $series['2026-03-01']['active_users']);
        $this->assertSame(2, $result['total_active_users']);
    }

    public function test_other_companies_and_apps_are_not_counted(): void
    {
        [$app, $company, $creator] = $this->context();
        $type = $this->makeType($app, $company, 'tenant');
        $payer = $this->makeUser();
        $foreignPayer = $this->makeUser();
        $otherCompany = Companies::factory()->create(['users_id' => $creator->getId()]);
        $otherCompanyType = new OrderTypes();
        $otherCompanyType->apps_id = $app->getId();
        $otherCompanyType->companies_id = $otherCompany->getId();
        $otherCompanyType->name = $type->name;
        $otherCompanyType->saveOrFail();

        $this->pay($app, $company, $this->makeOrder($app, $company, $type, $creator), $payer, '2026-02-01 15:00:00');
        $foreignOrder = $this->makeOrder($app, $otherCompany, $otherCompanyType, $creator);
        $this->pay($app, $otherCompany, $foreignOrder, $foreignPayer, '2026-02-01 15:00:00');
        $this->pay($app, $company, $foreignOrder, $foreignPayer, '2026-02-01 15:00:00');

        $result = new OrderReportService($app, $company)->activeUsers(
            orderTypeNames: [$type->name],
            since: self::SINCE,
            until: self::UNTIL,
            timezone: 'UTC',
            groupBy: null,
        );

        $this->assertSame(1, $result['total_active_users']);
        $this->assertSame(1, $result['total_paid_orders']);
    }

    public function test_an_unknown_timezone_is_rejected(): void
    {
        [$app, $company] = $this->context();

        $this->expectException(InvalidArgumentException::class);

        new OrderReportService($app, $company)->activeUsers(
            orderTypeNames: null,
            since: self::SINCE,
            until: self::UNTIL,
            timezone: 'Mars/Olympus',
            groupBy: null,
        );
    }

    public function test_neuron_tool_declares_scalar_params_and_takes_tenant_from_context(): void
    {
        [$app, $company, $creator] = $this->context();
        $type = $this->makeType($app, $company, 'tool');
        $payer = $this->makeUser();
        $this->pay($app, $company, $this->makeOrder($app, $company, $type, $creator), $payer, '2026-02-01 15:00:00');

        $tool = new ActiveUsersTool()->withContext($app, $company, $creator);
        $properties = collect($tool->getProperties())->keyBy(fn ($property) => $property->getName());

        $this->assertSame(
            ['order_types', 'since', 'until', 'timezone', 'group_by'],
            $properties->keys()->all()
        );
        $this->assertSame(PropertyType::STRING, $properties['order_types']->getType());

        $result = $tool->__invoke(
            order_types: $type->name,
            since: self::SINCE,
            until: self::UNTIL,
            group_by: 'month',
        );

        $this->assertSame('America/Santo_Domingo', $result['timezone']);
        $this->assertSame(1, $result['total_active_users']);
        $this->assertCount(1, $result['series']);
    }

    public function test_neuron_tool_answers_an_unknown_order_type_as_invalid_args(): void
    {
        [$app, $company, $creator] = $this->context();

        $result = new ActiveUsersTool()
            ->withContext($app, $company, $creator)
            ->__invoke(order_types: 'no_such_vertical_' . uniqid());

        $this->assertFalse($result['success']);
        $this->assertSame('invalid_args', $result['outcome']);
    }

    public function test_laravel_tool_returns_the_same_report(): void
    {
        [$app, $company, $creator] = $this->context();
        $type = $this->makeType($app, $company, 'laravel_tool');
        $payer = $this->makeUser();
        $this->pay($app, $company, $this->makeOrder($app, $company, $type, $creator), $payer, '2026-02-01 15:00:00');

        $tool = new LaravelActiveUsersTool()->withContext($app, $company);
        $result = json_decode((string) $tool->handle(new Request([
            'order_types' => [$type->name],
            'since' => self::SINCE,
            'until' => self::UNTIL,
        ])), true);

        $this->assertSame(1, $result['total_active_users']);
        $this->assertSame('America/Santo_Domingo', $result['timezone']);
    }
}
