<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Movipass;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Testing\TestResponse;
use Kanvas\Apps\Models\Apps;
use Kanvas\Auth\Actions\RegisterUsersAppAction;
use Kanvas\Connectors\Movipass\Activities\AssignImpoundOrderToPayerActivity;
use Kanvas\Connectors\Movipass\Enums\OrderTypeEnum;
use Kanvas\Enums\AppEnums;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Orders\Models\OrderTypes;
use Kanvas\Souk\Payments\Actions\CreatePaymentAction;
use Kanvas\Souk\Payments\Enums\PaymentStatusEnum;
use Kanvas\SystemModules\Repositories\SystemModulesRepository;
use Kanvas\Users\Models\Users;
use Kanvas\Workflow\Enums\WorkflowEnum;
use Kanvas\Workflow\Rules\Models\Action;
use Kanvas\Workflow\Rules\Models\RuleType;
use Tests\TestCase;

final class ImpoundPayerRuleFlowTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'commerce', 'crm', 'workflow'];

    public function testAPaidRetentionMovesToItsPayerThroughTheAdminRule(): void
    {
        $app = app(Apps::class);

        $action = Action::firstOrCreate(
            ['model_name' => AssignImpoundOrderToPayerActivity::class],
            ['name' => 'Movipass: assign paid retention to its payer']
        );

        $this->graphQL('
            mutation($input: CreateRuleInput!) {
                createRule(input: $input) { id name type { name } workflowActions { id } }
            }
        ', ['input' => [
            'name' => 'Movipass: assign paid retention to its payer',
            'rules_types_id' => (string) RuleType::firstOrCreate(
                ['name' => WorkflowEnum::AFTER_PAYMENT_INTENT->value],
                ['is_deleted' => 0]
            )->getId(),
            'systems_modules_id' => (string) SystemModulesRepository::getByModelName(Order::class, $app)->getId(),
            'pattern' => '1',
            'is_async' => false,
            'actions' => [['action_id' => (string) $action->getId(), 'weight' => 0]],
        ]], [], [
            AppEnums::KANVAS_APP_KEY_HEADER->getValue() => $app->keys()->first()->client_secret_id,
        ])->assertSuccessful()->assertGraphQLErrorFree();

        $order = $this->retention();
        $before = $this->owner($order)->json('data.orders.data.0');
        $this->assertSame((string) auth()->user()->getId(), (string) $before['user']['id']);

        $payer = $this->payer();
        $payment = new CreatePaymentAction($order, $payer);
        $payment->runWorkflow = false;
        $payment->execute([
            'status' => PaymentStatusEnum::PAID->value,
            'payment_method_type' => 'cash',
            'amount' => 100,
        ]);

        $after = $this->owner($order)->json('data.orders.data.0');
        $this->assertSame((string) $payer->getId(), (string) $after['user']['id']);
        $this->assertSame($payer->email, $after['user']['email']);
        $this->assertNotSame($before['people']['id'], $after['people']['id']);
    }

    private function retention(): Order
    {
        $app = app(Apps::class);
        $operator = auth()->user();
        $orderType = OrderTypes::withoutSyncingToSearch(fn () => OrderTypes::firstOrCreate(
            ['apps_id' => $app->getId(), 'name' => OrderTypeEnum::IMPOUND_LOT->value],
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

    private function owner(Order $order): TestResponse
    {
        return $this->graphQL('
            query {
                orders(where: { column: ID, operator: EQ, value: ' . $order->getId() . ' }) {
                    data {
                        id
                        payment_status
                        order_type { name }
                        user { id email }
                        people { id firstname lastname }
                    }
                }
            }
        ', [], [], [
            'X-Kanvas-App' => app(Apps::class)->key,
            'X-Kanvas-Location' => auth()->user()->getCurrentCompany()->branch->uuid,
        ])->assertSuccessful()->assertGraphQLErrorFree();
    }
}
