<?php

declare(strict_types=1);

namespace Tests\Connectors\Movipass;

use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Movipass\Actions\AddRoadsideAdditionalChargeAction;
use Kanvas\Connectors\Movipass\Actions\SyncRoadsideAssistanceStatusesAction;
use Kanvas\Connectors\Movipass\Enums\MovipassOrderStatusEnum;
use Kanvas\Connectors\Movipass\Enums\OrderTypeEnum;
use Kanvas\Connectors\Movipass\Enums\RoadsideAdditionalChargeConceptEnum;
use Kanvas\Connectors\Movipass\Enums\RoadsideAdditionalChargeStatusEnum;
use Kanvas\Connectors\Movipass\Handlers\MovipassHandler;
use Kanvas\Connectors\Movipass\Workflows\Activities\SyncMovipassRoadsideAssistanceActivity;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Orders\Models\OrderStatus;
use Kanvas\Souk\Orders\Models\OrderTypes;
use Kanvas\Souk\Payments\Enums\PaymentStatusEnum;
use Kanvas\Users\Models\Users;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\Enums\WorkflowEnum;
use Kanvas\Workflow\Models\StoredWorkflow;
use PHPUnit\Framework\Attributes\Group;
use Tests\Connectors\Movipass\Concerns\TogglesRegionalAssistance;
use Tests\Connectors\Traits\HasIntegrationCompany;
use Tests\TestCase;

#[Group('serial')]
final class RoadsideAdditionalChargeTest extends TestCase
{
    use HasIntegrationCompany;
    use TogglesRegionalAssistance;

    protected Apps $apps;
    protected Users $authUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->apps = app(Apps::class);
        $this->authUser = auth()->user();

        $this->rememberRegionalAssistanceSettings($this->apps);
        $this->toggleRegionalAssistance($this->apps, true);

        new SyncRoadsideAssistanceStatusesAction($this->apps)->execute();
    }

    protected function tearDown(): void
    {
        $this->restoreRegionalAssistanceSettings($this->apps);

        parent::tearDown();
    }

    public function testOutsideRegionalAssistanceTheResolvedCloseRunsAsBefore(): void
    {
        $this->toggleRegionalAssistance($this->apps, false);
        $case = $this->createCase(['complete_order' => true]);
        $this->attachCharge($case, $this->createChargeOrder($case, null));

        $result = $this->runEvent($case, WorkflowEnum::UPDATED);

        $this->assertSame('success', $result['status']);
        $this->assertSame('Mechanic completion request recorded, awaiting user confirmation', $result['message']);
        // The charge copy is not even read, let alone rewritten.
        $this->assertSame(
            RoadsideAdditionalChargeStatusEnum::PENDING->value,
            $case->refresh()->metadata['assistance_case']['additional_charges'][0]['status'],
        );
    }

    public function testAChargeCannotBeBilledOutsideRegionalAssistance(): void
    {
        $this->toggleRegionalAssistance($this->apps, false);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('only available for Asistencia Regional');

        new AddRoadsideAdditionalChargeAction(
            order: $this->createCase(),
            registeredBy: $this->authUser,
            concept: RoadsideAdditionalChargeConceptEnum::FUEL,
            amount: 10.00,
        )->execute();
    }

    public function testPayingTheChargeOrderApprovesTheChargeOnTheCase(): void
    {
        $case = $this->createCase();
        $chargeOrder = $this->createChargeOrder($case, PaymentStatusEnum::PAID->value);
        $this->attachCharge($case, $chargeOrder);

        $this->assertSame(PaymentStatusEnum::PAID->value, $chargeOrder->payment_status);

        $result = $this->runEvent($chargeOrder, WorkflowEnum::UPDATED);

        $this->assertSame('success', $result['status']);
        $this->assertSame('Additional charge approved', $result['message'], json_encode($result));

        $assistanceCase = $case->refresh()->metadata['assistance_case'];
        $this->assertSame(
            RoadsideAdditionalChargeStatusEnum::APPROVED->value,
            $assistanceCase['additional_charges'][0]['status'],
        );
        $this->assertFalse($assistanceCase['additional_payment_pending']);
    }

    public function testAnUnpaidChargeBlocksClosingTheCaseAsResolved(): void
    {
        $case = $this->createCase(['complete_order' => true]);
        $this->attachCharge($case, $this->createChargeOrder($case, null));

        $result = $this->runEvent($case, WorkflowEnum::UPDATED);

        $this->assertSame('error', $result['status']);
        $this->assertStringContainsString('additional charge is unpaid', $result['message']);
        $this->assertTrue($case->refresh()->metadata['assistance_case']['complete_order']);
    }

    public function testClosingTheCaseCancelsAnOutstandingCharge(): void
    {
        $case = $this->createCase();
        $chargeOrder = $this->createChargeOrder($case, null);
        $this->attachCharge($case, $chargeOrder);

        $case->transitionToStatus($this->authUser, MovipassOrderStatusEnum::SERVICE_CANCELLED->slug());
        $this->runEvent($case->refresh(), WorkflowEnum::UPDATED);

        $assistanceCase = $case->refresh()->metadata['assistance_case'];
        $this->assertSame(
            RoadsideAdditionalChargeStatusEnum::CANCELLED->value,
            $assistanceCase['additional_charges'][0]['status'],
        );
        $this->assertFalse($assistanceCase['additional_payment_pending']);
        $this->assertSame('canceled', $chargeOrder->refresh()->fulfillment_status);
    }

    public function testFuelCannotBeBilledOnANonFuelCase(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('does not apply');

        new AddRoadsideAdditionalChargeAction(
            order: $this->createCase(['service_type' => 'light_tow']),
            registeredBy: $this->authUser,
            concept: RoadsideAdditionalChargeConceptEnum::FUEL,
            amount: 10.00,
        )->execute();
    }

    public function testAChargeMustBePositive(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('greater than zero');

        new AddRoadsideAdditionalChargeAction(
            order: $this->createCase(),
            registeredBy: $this->authUser,
            concept: RoadsideAdditionalChargeConceptEnum::FUEL,
            amount: 0,
        )->execute();
    }

    private function runEvent(Order $order, WorkflowEnum $event): array
    {
        $this->setIntegration(
            $this->apps,
            IntegrationsEnum::MOVIPASS,
            MovipassHandler::class,
            $order->company,
            $this->authUser
        );
        Notification::fake();
        Queue::fake();

        return new SyncMovipassRoadsideAssistanceActivity(
            0,
            now()->toDateTimeString(),
            StoredWorkflow::make(),
            []
        )->execute($order, $this->apps, [
            'currentEventTypeName' => $event->value,
        ]);
    }

    private function createCase(array $assistanceCase = []): Order
    {
        $order = Order::factory()
            ->withCompanyId($this->authUser->getCurrentCompany()->getId())
            ->withUserId($this->authUser->getId())
            ->create([
                'order_types_id' => $this->orderTypeId(OrderTypeEnum::ROADSIDE_ASSISTANCE),
                'metadata' => ['assistance_case' => [
                    'service' => 'Fuel delivery',
                    'service_type' => 'fuel_delivery',
                    'location' => ['lat' => 13.6929, 'lng' => -89.2182],
                    ...$assistanceCase,
                ]],
            ]);

        $order->order_status_id = (int) OrderStatus::where('apps_id', $this->apps->getId())
            ->where('slug', MovipassOrderStatusEnum::REQUEST_SUBMITTED->slug())
            ->whereHas(
                'orderType',
                fn ($query) => $query->where('name', OrderTypeEnum::ROADSIDE_ASSISTANCE->value)
            )
            ->firstOrFail()
            ->getId();
        $order->saveQuietly();

        return $order->refresh();
    }

    /**
     * The payment lands after the charge order exists, as in production; setting it at creation
     * lets whatever the CREATED workflow does to a new order overwrite it.
     */
    private function createChargeOrder(Order $case, ?string $paymentStatus): Order
    {
        $chargeOrder = Order::factory()
            ->withCompanyId($case->companies_id)
            ->withUserId($case->users_id)
            ->create([
                'order_types_id' => $this->orderTypeId(OrderTypeEnum::ROADSIDE_ADDITIONAL_CHARGE),
                'parent_id' => $case->getId(),
            ]);

        $chargeOrder->payment_status = $paymentStatus;
        $chargeOrder->saveQuietly();

        return $chargeOrder->refresh();
    }

    private function attachCharge(Order $case, Order $chargeOrder): void
    {
        $metadata = $case->metadata;
        $metadata['assistance_case']['additional_charges'] = [[
            'id' => 'charge-1',
            'concept' => RoadsideAdditionalChargeConceptEnum::FUEL->value,
            'amount' => 10.00,
            'total' => 13.56,
            'status' => RoadsideAdditionalChargeStatusEnum::PENDING->value,
            'order_id' => $chargeOrder->getId(),
        ]];
        $metadata['assistance_case']['additional_payment_pending'] = true;

        $case->metadata = $metadata;
        $case->saveQuietly();
    }

    private function orderTypeId(OrderTypeEnum $type): int
    {
        return OrderTypes::firstOrCreate([
            'name' => $type->value,
            'apps_id' => $this->apps->getId(),
        ])->getId();
    }
}
