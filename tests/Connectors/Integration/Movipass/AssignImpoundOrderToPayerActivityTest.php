<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Movipass;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Queue;
use Kanvas\Apps\Models\Apps;
use Kanvas\Auth\Actions\RegisterUsersAppAction;
use Kanvas\Connectors\Movipass\Activities\AssignImpoundOrderToPayerActivity;
use Kanvas\Connectors\Movipass\Enums\OrderTypeEnum;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Orders\Models\OrderTypes;
use Kanvas\Souk\Payments\Actions\CreatePaymentAction;
use Kanvas\Souk\Payments\Enums\PaymentStatusEnum;
use Kanvas\Users\Models\Users;
use Kanvas\Workflow\Models\StoredWorkflow;
use Tests\TestCase;

final class AssignImpoundOrderToPayerActivityTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'commerce', 'crm'];

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
    }

    public function testAPaidRetentionMovesToItsPayerAndThePayersCustomer(): void
    {
        $payer = $this->payer();
        $order = $this->order(OrderTypeEnum::IMPOUND_LOT->value);
        $operatorId = (int) $order->users_id;
        $this->pay($order, $payer);

        $result = $this->activity()->execute($order, app(Apps::class), []);

        $order->refresh();
        $this->assertSame('success', $result['status']);
        $this->assertSame($payer->getId(), (int) $order->users_id);
        $this->assertNotSame($operatorId, (int) $order->users_id);
        $this->assertSame($payer->email, People::find($order->people_id)?->getEmails()->first()?->value);
    }

    public function testARetentionAlreadyOwnedByItsPayerIsLeftAlone(): void
    {
        $payer = $this->payer();
        $order = $this->order(OrderTypeEnum::IMPOUND_LOT->value);
        $this->pay($order, $payer);
        $this->activity()->execute($order, app(Apps::class), []);

        $result = $this->activity()->execute($order->refresh(), app(Apps::class), []);

        $this->assertSame('skipped', $result['status']);
        $this->assertSame($payer->getId(), (int) $order->refresh()->users_id);
    }

    public function testOtherOrderTypesAreNotTouched(): void
    {
        $order = $this->order(OrderTypeEnum::MOVIPASS->value);
        $operatorId = (int) $order->users_id;
        $this->pay($order, $this->payer());

        $result = $this->activity()->execute($order, app(Apps::class), []);

        $this->assertSame('skipped', $result['status']);
        $this->assertSame($operatorId, (int) $order->refresh()->users_id);
    }

    public function testARetentionWithoutAPaidPaymentIsNotTouched(): void
    {
        $order = $this->order(OrderTypeEnum::IMPOUND_LOT->value);
        $operatorId = (int) $order->users_id;

        $result = $this->activity()->execute($order, app(Apps::class), []);

        $this->assertSame('skipped', $result['status']);
        $this->assertSame($operatorId, (int) $order->refresh()->users_id);
    }

    private function order(string $orderTypeName): Order
    {
        $app = app(Apps::class);
        $operator = auth()->user();
        $orderType = OrderTypes::withoutSyncingToSearch(fn () => OrderTypes::firstOrCreate(
            ['apps_id' => $app->getId(), 'name' => $orderTypeName],
            ['companies_id' => 0]
        ));

        return Order::withoutSyncingToSearch(fn () => Order::factory()
            ->withAppId($app->getId())
            ->withCompanyId($operator->getCurrentCompany()->getId())
            ->withUserId($operator->getId())
            ->create(['order_types_id' => $orderType->getId(), 'total_net_amount' => 100, 'total_gross_amount' => 100]));
    }

    private function payer(): Users
    {
        $payer = Users::factory()->create();
        auth()->user()->getCurrentCompany()->associateUserApp($payer, app(Apps::class), 1);
        new RegisterUsersAppAction($payer, app(Apps::class))->execute((string) $payer->password);

        return $payer;
    }

    private function pay(Order $order, Users $payer): void
    {
        $action = new CreatePaymentAction($order, $payer);
        $action->runWorkflow = false;
        $action->execute([
            'status' => PaymentStatusEnum::PAID->value,
            'payment_method_type' => 'cash',
            'amount' => 100,
        ]);
    }

    private function activity(): AssignImpoundOrderToPayerActivity
    {
        return new AssignImpoundOrderToPayerActivity(
            0,
            now()->toDateTimeString(),
            StoredWorkflow::make(),
            []
        );
    }
}
