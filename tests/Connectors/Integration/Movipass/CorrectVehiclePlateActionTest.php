<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Movipass;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Auth;
use Kanvas\Activities\Models\Activity;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Movipass\Actions\Corrections\CorrectVehiclePlateAction;
use Kanvas\Connectors\Movipass\Enums\MovipassOrderStatusEnum;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Regions\Models\Regions;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Orders\Models\OrderStatus;
use Kanvas\Souk\Orders\Models\OrderTypes;
use Kanvas\Users\Models\Users;
use Tests\TestCase;

final class CorrectVehiclePlateActionTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        if (getenv('GITHUB_ACTIONS')) {
            $this->markTestSkipped('Movipass integration tests are skipped in CI');
        }
    }

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

    public function testItCorrectsVehiclePlate(): void
    {
        $app = app(Apps::class);
        $user = Auth::user();

        $order = $this->createTestOrder($app, $user, [
            'order_number' => 12345,
            'metadata' => ['data' => ['vehiclePlate' => 'ABC123', 'vehicleBrand' => 'Toyota']],
            'reference' => 'Toyota / ABC123 - #12345',
        ]);

        $result = Order::withoutSyncingToSearch(
            fn () => new CorrectVehiclePlateAction($order, $user, 'XYZ789', 'Placa registrada incorrectamente')->execute()
        );

        $this->assertEquals('XYZ789', $result->metadata['data']['vehiclePlate']);
        $this->assertEquals('Toyota / XYZ789 - #12345', $result->reference);

        $log = Activity::where('subject_id', $order->id)
            ->where('subject_type', Order::class)
            ->where('description', 'correct-plate')
            ->first();

        $this->assertNotNull($log);
        $this->assertEquals('ABC123', $log->properties['changes']['vehiclePlate']['old']);
        $this->assertEquals('XYZ789', $log->properties['changes']['vehiclePlate']['new']);
        $this->assertEquals('Placa registrada incorrectamente', $log->properties['reason']);
    }

    public function testItAppendsEvidenceImages(): void
    {
        $app = app(Apps::class);
        $user = Auth::user();

        $order = $this->createTestOrder($app, $user, [
            'order_number' => 12346,
            'metadata' => [
                'data' => [
                    'vehiclePlate' => 'OLD001',
                    'vehicleBrand' => 'Honda',
                    'images' => ['https://s3.example.com/existing.jpg'],
                ],
            ],
            'reference' => 'Honda / OLD001 - #12346',
        ]);

        $result = Order::withoutSyncingToSearch(
            fn () => new CorrectVehiclePlateAction(
                $order,
                $user,
                'NEW002',
                'Corrección de placa',
                ['https://s3.example.com/evidence1.jpg', 'https://s3.example.com/evidence2.jpg'],
            )->execute()
        );

        $images = $result->metadata['data']['images'];
        $this->assertContains('https://s3.example.com/existing.jpg', $images);
        $this->assertContains('https://s3.example.com/evidence1.jpg', $images);
        $this->assertContains('https://s3.example.com/evidence2.jpg', $images);
        $this->assertCount(3, $images);
    }

    public function testItBlocksCorrectionOnFinalStatus(): void
    {
        $app = app(Apps::class);
        $user = Auth::user();

        $orderType = OrderTypes::where('name', 'impound_lot')->fromApp($app)->first();

        if (! $orderType) {
            $this->markTestSkipped('impound_lot order type not set up in this environment');
        }

        $releasedStatus = OrderStatus::where('slug', MovipassOrderStatusEnum::RELEASED->value)
            ->where('order_types_id', $orderType->id)
            ->first();

        if (! $releasedStatus) {
            $this->markTestSkipped('released_from_lot status not found — run movipass:setup-impound-lot first');
        }

        $order = $this->createTestOrder($app, $user, [
            'order_number' => 12347,
            'order_status_id' => $releasedStatus->id,
            'metadata' => ['data' => ['vehiclePlate' => 'FIN001', 'vehicleBrand' => 'Ford']],
            'reference' => 'Ford / FIN001 - #12347',
        ]);

        $this->expectException(ValidationException::class);

        Order::withoutSyncingToSearch(
            fn () => new CorrectVehiclePlateAction($order, $user, 'FIN999', 'should fail')->execute()
        );
    }
}
