<?php

declare(strict_types=1);

namespace Tests\GraphQL\Souk;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Inventory\Variants\Models\Variants;
use Kanvas\Regions\Models\Regions;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Orders\Models\OrderItem;
use Kanvas\Souk\Orders\Models\OrderStatus;
use Kanvas\Souk\Orders\Models\OrderTransitionHistory;
use Kanvas\Souk\Payments\Models\Payments;
use Kanvas\Users\Models\Users;
use Tests\TestCase;

final class AmendOrderTest extends TestCase
{
    use DatabaseTransactions;

    protected Apps $kanvasApp;
    protected Users $kanvasUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->kanvasApp = app(Apps::class);
        $this->kanvasUser = Auth::user();

        Gate::before(fn () => true);
    }

    private function createTestOrder(array $metadataOverrides = []): Order
    {
        $company = $this->kanvasUser->getCurrentCompany();
        $region = Regions::getDefault($company, $this->kanvasApp);

        $person = People::withoutSyncingToSearch(
            fn () => People::factory()
                ->withAppId($this->kanvasApp->getId())
                ->withCompanyId($company->getId())
                ->create()
        );

        return Order::withoutSyncingToSearch(
            fn () => Order::factory()
                ->withAppId($this->kanvasApp->getId())
                ->withCompanyId($company->getId())
                ->withUserId($this->kanvasUser->getId())
                ->create(array_merge([
                    'region_id' => $region->getId(),
                    'people_id' => $person->id,
                ], $metadataOverrides))
        );
    }

    public function testAmendOrderCorrectPlateUpdatesMetadataAndReference(): void
    {
        $order = $this->createTestOrder([
            'order_number' => 99901,
            'metadata' => ['data' => ['vehiclePlate' => 'OLD123', 'vehicleBrand' => 'Toyota']],
            'reference' => 'Toyota / OLD123 - #99901',
        ]);

        $response = $this->graphQL('
            mutation($order_id: ID!, $data: Mixed) {
                amendOrder(
                    order_id: $order_id
                    correction_type: "correct-plate"
                    reason: "Placa registrada incorrectamente"
                    data: $data
                ) {
                    id
                    reference
                    metadata
                }
            }
        ', [
            'order_id' => $order->id,
            'data' => ['new_plate' => 'NEW999'],
        ]);

        $response->assertSuccessful();

        $result = $response->json('data.amendOrder');

        $this->assertSame('Toyota / NEW999 - #99901', $result['reference']);
        $this->assertSame('NEW999', $result['metadata']['data']['vehiclePlate']);
    }

    public function testAmendOrderAddObservationsUpdatesMetadata(): void
    {
        $order = $this->createTestOrder([
            'order_number' => 99902,
            'metadata' => ['data' => ['vehiclePlate' => 'ABC123']],
        ]);

        $response = $this->graphQL('
            mutation($order_id: ID!, $data: Mixed) {
                amendOrder(
                    order_id: $order_id
                    correction_type: "add-observations"
                    reason: "Daños visibles"
                    data: $data
                ) {
                    id
                    metadata
                }
            }
        ', [
            'order_id' => $order->id,
            'data' => ['observations' => 'Vehículo con daños en parachoque'],
        ]);

        $response->assertSuccessful();

        $this->assertSame(
            'Vehículo con daños en parachoque',
            $response->json('data.amendOrder.metadata.data.observations')
        );
    }

    public function testOrderActivityLogsExposesCorrections(): void
    {
        $order = $this->createTestOrder([
            'order_number' => 99903,
            'metadata' => ['data' => ['vehiclePlate' => 'LOG001', 'vehicleBrand' => 'Honda']],
            'reference' => 'Honda / LOG001 - #99903',
        ]);

        $response = $this->graphQL('
            mutation($order_id: ID!, $data: Mixed) {
                amendOrder(
                    order_id: $order_id
                    correction_type: "correct-plate"
                    reason: "Test log query"
                    data: $data
                ) {
                    id
                    activityLogs {
                        data {
                            id
                            description
                            properties
                        }
                    }
                }
            }
        ', [
            'order_id' => $order->id,
            'data' => ['new_plate' => 'LOG999'],
        ]);

        $response->assertSuccessful();

        $logs = $response->json('data.amendOrder.activityLogs.data');
        $this->assertNotEmpty($logs);

        $correctionLog = collect($logs)->firstWhere('description', 'correct-plate');
        $this->assertNotNull($correctionLog);
        $this->assertSame('LOG001', $correctionLog['properties']['changes']['vehiclePlate']['old']);
        $this->assertSame('LOG999', $correctionLog['properties']['changes']['vehiclePlate']['new']);
        $this->assertSame('Test log query', $correctionLog['properties']['reason']);
    }

    public function testActivityLogsExposeCauserUser(): void
    {
        $order = $this->createTestOrder([
            'order_number' => 99911,
            'metadata' => ['data' => ['vehiclePlate' => 'USR001', 'vehicleBrand' => 'Kia']],
            'reference' => 'Kia / USR001 - #99911',
        ]);

        $response = $this->graphQL('
            mutation($order_id: ID!, $data: Mixed) {
                amendOrder(
                    order_id: $order_id
                    correction_type: "correct-plate"
                    reason: "Expose causer user"
                    data: $data
                ) {
                    id
                    activityLogs {
                        data {
                            causer_id
                            user {
                                id
                            }
                        }
                    }
                }
            }
        ', [
            'order_id' => $order->id,
            'data' => ['new_plate' => 'USR999'],
        ]);

        $response->assertSuccessful();

        $logs = $response->json('data.amendOrder.activityLogs.data');
        $this->assertNotEmpty($logs);

        $log = collect($logs)->firstWhere('causer_id', (int) $this->kanvasUser->getId());
        $this->assertNotNull($log, 'activity log should be attributed to the acting user');
        $this->assertSame((string) $this->kanvasUser->getId(), (string) $log['user']['id']);
    }

    public function testAmendOrderReturnsErrorOnUnknownCorrectionType(): void
    {
        $order = $this->createTestOrder();

        $this->graphQL('
            mutation($order_id: ID!) {
                amendOrder(
                    order_id: $order_id
                    correction_type: "non-existent-type"
                    reason: "test"
                ) {
                    id
                }
            }
        ', ['order_id' => $order->id])
            ->assertGraphQLErrorMessage('Unknown correction type: non-existent-type');
    }

    public function testAmendOrderAssociatePaymentTransfersFromWrongOrder(): void
    {
        $company = $this->kanvasUser->getCurrentCompany();
        $region = Regions::getDefault($company, $this->kanvasApp);

        $person = People::withoutSyncingToSearch(
            fn () => People::factory()
                ->withAppId($this->kanvasApp->getId())
                ->withCompanyId($company->getId())
                ->create()
        );

        $wrongOrder = Order::withoutSyncingToSearch(
            fn () => Order::factory()
                ->withAppId($this->kanvasApp->getId())
                ->withCompanyId($company->getId())
                ->withUserId($this->kanvasUser->getId())
                ->create(['region_id' => $region->getId(), 'people_id' => $person->id, 'order_number' => 99904])
        );

        $correctOrder = Order::withoutSyncingToSearch(
            fn () => Order::factory()
                ->withAppId($this->kanvasApp->getId())
                ->withCompanyId($company->getId())
                ->withUserId($this->kanvasUser->getId())
                ->create(['region_id' => $region->getId(), 'people_id' => $person->id, 'order_number' => 99905])
        );

        // Payment linked to the wrong order
        $payment = new Payments();
        $payment->apps_id = $this->kanvasApp->getId();
        $payment->companies_id = $company->getId();
        $payment->users_id = $this->kanvasUser->getId();
        $payment->amount = 4000;
        $payment->currency = 'DOP';
        $payment->payment_date = now()->toDateString();
        $payment->concept = 'Pago orden equivocada';
        $payment->status = 'paid';
        $payment->payment_method = 'cash';
        $payment->payable_id = $wrongOrder->id;
        $payment->payable_type = Order::class;
        $payment->save();

        $response = $this->graphQL('
            mutation($order_id: ID!, $data: Mixed) {
                amendOrder(
                    order_id: $order_id
                    correction_type: "associate-payment"
                    reason: "Pago registrado en orden incorrecta"
                    data: $data
                ) {
                    id
                    payments {
                        uuid
                        status
                    }
                }
            }
        ', [
            'order_id' => $correctOrder->id,
            'data' => ['payment_uuid' => $payment->uuid],
        ]);

        $response->assertSuccessful();

        $payments = $response->json('data.amendOrder.payments');
        $this->assertNotEmpty($payments);
        $this->assertSame($payment->uuid, $payments[0]['uuid']);

        // Payment is now on the correct order
        $payment->refresh();
        $this->assertSame($correctOrder->id, (int) $payment->payable_id);

        // Wrong order no longer has the payment and its payment_status was reset to unpaid
        $this->assertEmpty($wrongOrder->payments()->where('uuid', $payment->uuid)->get());
        $wrongOrder->refresh();
        $this->assertSame('unpaid', $wrongOrder->payment_status);
    }

    public function testAmendOrderAssociatePaymentRollsBackStatusAndFulfillmentWhenOrderPastPaid(): void
    {
        // Simulate order that went awaiting_payment → paid → active (currently at active)
        $prePaymentStatus = OrderStatus::where('slug', 'awaiting_payment')->first();
        $paidStatus = OrderStatus::where('slug', 'paid')
            ->when($prePaymentStatus, fn ($q) => $q->where('order_types_id', $prePaymentStatus->order_types_id))
            ->first();
        $postPaidStatus = OrderStatus::where('slug', 'active')
            ->when($paidStatus, fn ($q) => $q->where('order_types_id', $paidStatus->order_types_id))
            ->first();

        if (! $prePaymentStatus || ! $paidStatus || ! $postPaidStatus) {
            $this->markTestSkipped('Required order statuses (awaiting_payment/paid/active) not found in test DB');
        }

        $company = $this->kanvasUser->getCurrentCompany();
        $region = Regions::getDefault($company, $this->kanvasApp);

        $person = People::withoutSyncingToSearch(
            fn () => People::factory()
                ->withAppId($this->kanvasApp->getId())
                ->withCompanyId($company->getId())
                ->create()
        );

        // Wrong order is currently at 'active' (past paid), with fulfillment already set to 'fulfilled'
        $wrongOrder = Order::withoutSyncingToSearch(
            fn () => Order::factory()
                ->withAppId($this->kanvasApp->getId())
                ->withCompanyId($company->getId())
                ->withUserId($this->kanvasUser->getId())
                ->create(['region_id' => $region->getId(), 'people_id' => $person->id, 'order_number' => 99906])
        );

        $wrongOrder->updateQuietly([
            'order_status_id' => $postPaidStatus->id,
            'fulfillment_status' => 'fulfilled',
        ]);

        // History: awaiting_payment → paid (old, not current)
        OrderTransitionHistory::create([
            'apps_id' => $this->kanvasApp->getId(),
            'companies_id' => $company->getId(),
            'order_id' => $wrongOrder->id,
            'transition_id' => null,
            'from_status_id' => $prePaymentStatus->id,
            'to_status_id' => $paidStatus->id,
            'description' => 'transitioned to paid',
            'metadata' => [],
            'is_current' => false,
            'changed_at' => now(),
            'changed_by' => $this->kanvasUser->getId(),
        ]);

        // History: paid → active (current)
        OrderTransitionHistory::create([
            'apps_id' => $this->kanvasApp->getId(),
            'companies_id' => $company->getId(),
            'order_id' => $wrongOrder->id,
            'transition_id' => null,
            'from_status_id' => $paidStatus->id,
            'to_status_id' => $postPaidStatus->id,
            'description' => 'transitioned to active',
            'metadata' => [],
            'is_current' => true,
            'changed_at' => now(),
            'changed_by' => $this->kanvasUser->getId(),
        ]);

        $correctOrder = Order::withoutSyncingToSearch(
            fn () => Order::factory()
                ->withAppId($this->kanvasApp->getId())
                ->withCompanyId($company->getId())
                ->withUserId($this->kanvasUser->getId())
                ->create(['region_id' => $region->getId(), 'people_id' => $person->id, 'order_number' => 99907])
        );

        $payment = new Payments();
        $payment->apps_id = $this->kanvasApp->getId();
        $payment->companies_id = $company->getId();
        $payment->users_id = $this->kanvasUser->getId();
        $payment->amount = 4000;
        $payment->currency = 'DOP';
        $payment->payment_date = now()->toDateString();
        $payment->concept = 'Pago orden equivocada';
        $payment->status = 'paid';
        $payment->payment_method = 'cash';
        $payment->payable_id = $wrongOrder->id;
        $payment->payable_type = Order::class;
        $payment->save();

        $response = $this->graphQL('
            mutation($order_id: ID!, $data: Mixed) {
                amendOrder(
                    order_id: $order_id
                    correction_type: "associate-payment"
                    reason: "Pago registrado en orden incorrecta"
                    data: $data
                ) { id }
            }
        ', [
            'order_id' => $correctOrder->id,
            'data' => ['payment_uuid' => $payment->uuid],
        ]);

        $response->assertSuccessful();
        if ($response->json('errors')) {
            $this->fail('GraphQL errors: ' . json_encode($response->json('errors')));
        }

        $wrongOrder->refresh();

        // order_status rolls back to the status BEFORE the paid transition
        $this->assertSame($prePaymentStatus->id, (int) $wrongOrder->order_status_id);

        // fulfillment_status resets to pending
        $this->assertSame('pending', $wrongOrder->fulfillment_status);

        // New is_current history row goes from current (active) back to pre-payment (awaiting_payment)
        $newHistory = OrderTransitionHistory::where('order_id', $wrongOrder->id)
            ->where('is_current', true)
            ->first();
        $this->assertNotNull($newHistory);
        $this->assertSame($postPaidStatus->id, (int) $newHistory->from_status_id);
        $this->assertSame($prePaymentStatus->id, (int) $newHistory->to_status_id);
    }

    public function testAmendOrderRelocateVehicleSwapsVariantAndUpdatesMetadata(): void
    {
        $impoundLotVariants = Variants::whereHas(
            'product.productType',
            fn ($q) => $q->where('slug', 'impound_lot')
        )->take(2)->get();

        if ($impoundLotVariants->count() < 2) {
            $this->markTestSkipped('Need at least 2 impound_lot variants in test DB');
        }

        $oldVariant = $impoundLotVariants->first();
        $newVariant = $impoundLotVariants->last();

        $order = $this->createTestOrder([
            'order_number' => 99910,
            'metadata' => ['data' => ['carDeposit' => ['name' => 'Centro Viejo', 'address' => 'Av. Antigua']]],
        ]);

        $orderItem = new OrderItem();
        $orderItem->apps_id = $this->kanvasApp->getId();
        $orderItem->order_id = $order->id;
        $orderItem->variant_id = $oldVariant->id;
        $orderItem->variant_name = $oldVariant->name;
        $orderItem->product_name = $oldVariant->product->name;
        $orderItem->product_sku = $oldVariant->sku ?? 'LOC-001';
        $orderItem->quantity = 1;
        $orderItem->unit_price_net_amount = 0;
        $orderItem->unit_price_gross_amount = 0;
        $orderItem->quantity_fulfilled = 0;
        $orderItem->currency = 'DOP';
        $orderItem->save();

        $response = $this->graphQL('
            mutation($order_id: ID!, $data: Mixed) {
                amendOrder(
                    order_id: $order_id
                    correction_type: "relocate"
                    reason: "Vehículo trasladado a otro centro"
                    data: $data
                ) {
                    id
                    metadata
                    activityLogs {
                        data {
                            description
                            properties
                        }
                    }
                }
            }
        ', [
            'order_id' => $order->id,
            'data' => [
                'variant_id' => $newVariant->id,
                'car_deposit' => ['name' => 'Centro Nuevo', 'address' => 'Av. X'],
            ],
        ]);

        $response->assertSuccessful();
        if ($response->json('errors')) {
            $this->fail('GraphQL errors: ' . json_encode($response->json('errors')));
        }

        $result = $response->json('data.amendOrder');

        $this->assertSame('Centro Nuevo', $result['metadata']['data']['carDeposit']['name']);

        $logs = $result['activityLogs']['data'];
        $relocateLog = collect($logs)->firstWhere('description', 'relocate');
        $this->assertNotNull($relocateLog);
        $this->assertSame($newVariant->name, $relocateLog['properties']['changes']['center']['new']['variant_name']);

        $this->assertTrue(
            OrderItem::where('order_id', $order->id)
                ->where('variant_id', $newVariant->id)
                ->exists()
        );
    }
}
