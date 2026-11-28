<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Movipass;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Queue;
use Kanvas\Apps\Models\Apps;
use Kanvas\Auth\Actions\RegisterUsersAppAction;
use Kanvas\Connectors\Movipass\Enums\OrderTypeEnum;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Orders\Models\OrderTypes;
use Kanvas\Souk\Payments\Actions\CreatePaymentAction;
use Kanvas\Souk\Payments\Enums\PaymentStatusEnum;
use Kanvas\Users\Models\Users;
use Tests\TestCase;

final class BackfillImpoundPayerCommandTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'commerce', 'crm'];

    private const string COMMAND = 'kanvas-movipass:backfill-impound-payer';

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
    }

    public function testDryRunListsWithoutMovingAndARealRunMovesOnlyPaidRetentionsOnce(): void
    {
        $app = app(Apps::class);
        $operatorId = (int) auth()->user()->getId();
        $payer = $this->payer();

        $paidRetention = $this->order(OrderTypeEnum::IMPOUND_LOT->value);
        $this->pay($paidRetention, $payer);
        $unpaidRetention = $this->order(OrderTypeEnum::IMPOUND_LOT->value);
        $paidParking = $this->order(OrderTypeEnum::MOVIPASS->value);
        $this->pay($paidParking, $payer);

        $this->artisan(self::COMMAND, ['app_id' => $app->getId(), '--dry-run' => true])
            ->expectsOutputToContain("Order {$paidRetention->getId()}: user {$operatorId} -> {$payer->getId()}")
            ->assertSuccessful();
        $this->assertSame($operatorId, (int) $paidRetention->refresh()->users_id);

        $this->artisan(self::COMMAND, ['app_id' => $app->getId()])->assertSuccessful();

        $this->assertSame($payer->getId(), (int) $paidRetention->refresh()->users_id);
        $this->assertSame($operatorId, (int) $unpaidRetention->refresh()->users_id);
        $this->assertSame($operatorId, (int) $paidParking->refresh()->users_id);

        $this->artisan(self::COMMAND, ['app_id' => $app->getId(), '--dry-run' => true])
            ->doesntExpectOutputToContain("Order {$paidRetention->getId()}:")
            ->assertSuccessful();
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
}
