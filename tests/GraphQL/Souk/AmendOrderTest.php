<?php

declare(strict_types=1);

namespace Tests\GraphQL\Souk;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Regions\Models\Regions;
use Kanvas\Souk\Orders\Models\Order;
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

    public function testAmendOrderAssociatePaymentRollsBackPaidOrderStatus(): void
    {
        $paidStatus = OrderStatus::where('slug', 'paid')->first();
        if (! $paidStatus) {
            $this->markTestSkipped('No paid OrderStatus found in test DB');
        }

        $previousStatus = OrderStatus::where('order_types_id', $paidStatus->order_types_id)
            ->where('id', '!=', $paidStatus->id)
            ->first();
        if (! $previousStatus) {
            $this->markTestSkipped('No previous OrderStatus found');
        }

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
                ->create(['region_id' => $region->getId(), 'people_id' => $person->id, 'order_number' => 99906])
        );

        $wrongOrder->updateQuietly(['order_status_id' => $paidStatus->id]);

        OrderTransitionHistory::create([
            'apps_id' => $this->kanvasApp->getId(),
            'companies_id' => $company->getId(),
            'order_id' => $wrongOrder->id,
            'transition_id' => null,
            'from_status_id' => $previousStatus->id,
            'to_status_id' => $paidStatus->id,
            'description' => 'transitioned to paid',
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
        $this->assertSame($previousStatus->id, (int) $wrongOrder->order_status_id);

        $newHistory = OrderTransitionHistory::where('order_id', $wrongOrder->id)
            ->where('is_current', true)
            ->first();
        $this->assertNotNull($newHistory);
        $this->assertSame($paidStatus->id, (int) $newHistory->from_status_id);
        $this->assertSame($previousStatus->id, (int) $newHistory->to_status_id);
    }
}
