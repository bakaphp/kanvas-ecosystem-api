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
}
