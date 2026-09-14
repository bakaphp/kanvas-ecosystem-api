<?php

declare(strict_types=1);

namespace Tests\GraphQL\Souk;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Auth;
use Kanvas\Activities\Models\Activity;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Movipass\Actions\Corrections\CorrectVehiclePlateAction;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Regions\Models\Regions;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Users\Models\Users;
use Tests\TestCase;

final class OrderActivityLogsTest extends TestCase
{
    use DatabaseTransactions;

    private const QUERY = '
        query orderActivityLogs($order_number: String) {
            orderActivityLogs(
                order_number: $order_number
                orderBy: [{ column: CREATED_AT, order: DESC }]
                first: 50
            ) {
                data {
                    id
                    log_name
                    description
                    entity_id
                    properties
                }
                paginatorInfo { total }
            }
        }
    ';

    private function createTestOrder(Apps $app, Users $user, array $overrides = []): Order
    {
        $company = $user->getCurrentCompany();
        $region = Regions::getDefault($company, $app);

        $person = People::withoutSyncingToSearch(
            fn () => People::factory()
                ->withAppId($app->getId())
                ->withCompanyId($company->getId())
                ->create()
        );

        return Order::withoutSyncingToSearch(
            fn () => Order::factory()
                ->withAppId($app->getId())
                ->withCompanyId($company->getId())
                ->withUserId($user->getId())
                ->create(array_merge([
                    'region_id' => $region->getId(),
                    'people_id' => $person->id,
                ], $overrides))
        );
    }

    private function correctPlate(Order $order, Users $user, string $newPlate): void
    {
        Order::withoutSyncingToSearch(
            fn () => new CorrectVehiclePlateAction($order, $user, $newPlate, 'audit view test')->execute()
        );
    }

    public function testItListsCorrectionsWithoutAskingForAnOrder(): void
    {
        $app = app(Apps::class);
        $user = Auth::user();

        $order = $this->createTestOrder($app, $user, [
            'order_number' => 91001,
            'metadata' => ['data' => ['vehiclePlate' => 'AUD001', 'vehicleBrand' => 'Toyota']],
            'reference' => 'Toyota / AUD001 - #91001',
        ]);

        $this->correctPlate($order, $user, 'AUD999');

        $response = $this->graphQL(self::QUERY);

        $response->assertSuccessful();

        $entries = collect($response->json('data.orderActivityLogs.data'))
            ->where('entity_id', $order->id);

        $this->assertCount(1, $entries);
        $this->assertEquals('correct-plate', $entries->first()['description']);
        $this->assertEquals($order->getActivityLogName(), $entries->first()['log_name']);
        $this->assertEquals('audit view test', $entries->first()['properties']['reason']);
    }

    public function testItFiltersByOrderNumber(): void
    {
        $app = app(Apps::class);
        $user = Auth::user();

        $wanted = $this->createTestOrder($app, $user, [
            'order_number' => 91002,
            'metadata' => ['data' => ['vehiclePlate' => 'WNT001', 'vehicleBrand' => 'Kia']],
            'reference' => 'Kia / WNT001 - #91002',
        ]);

        $other = $this->createTestOrder($app, $user, [
            'order_number' => 91003,
            'metadata' => ['data' => ['vehiclePlate' => 'OTH001', 'vehicleBrand' => 'Honda']],
            'reference' => 'Honda / OTH001 - #91003',
        ]);

        $this->correctPlate($wanted, $user, 'WNT999');
        $this->correctPlate($other, $user, 'OTH999');

        $response = $this->graphQL(self::QUERY, ['order_number' => '91002']);

        $response->assertSuccessful();

        $ids = collect($response->json('data.orderActivityLogs.data'))->pluck('entity_id');

        $this->assertContains($wanted->id, $ids);
        $this->assertNotContains($other->id, $ids);
    }

    public function testItHidesLogsFromOtherTenants(): void
    {
        $app = app(Apps::class);
        $user = Auth::user();

        $order = $this->createTestOrder($app, $user, [
            'order_number' => 91004,
            'metadata' => ['data' => ['vehiclePlate' => 'TEN001', 'vehicleBrand' => 'Ford']],
            'reference' => 'Ford / TEN001 - #91004',
        ]);

        $this->correctPlate($order, $user, 'TEN999');

        $foreign = Activity::query()->where('subject_id', $order->id)->firstOrFail()->replicate();
        $foreign->log_name = 'order-999999-999999';
        $foreign->save();

        $response = $this->graphQL(self::QUERY);

        $response->assertSuccessful();

        $logNames = collect($response->json('data.orderActivityLogs.data'))->pluck('log_name')->unique();

        $this->assertNotContains('order-999999-999999', $logNames);
        $this->assertContains($order->getActivityLogName(), $logNames);
    }
}
