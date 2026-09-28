<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Movipass;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Auth;
use Kanvas\Activities\Models\Activity;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Movipass\Actions\Corrections\AddObservationsAction;
use Kanvas\Connectors\Movipass\Enums\MovipassOrderStatusEnum;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Regions\Models\Regions;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Orders\Models\OrderStatus;
use Kanvas\Souk\Orders\Models\OrderTypes;
use Kanvas\Users\Models\Users;
use Tests\TestCase;

final class AddObservationsActionTest extends TestCase
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

    public function testItAddsObservations(): void
    {
        $app = app(Apps::class);
        $user = Auth::user();

        $order = $this->createTestOrder($app, $user, [
            'order_number' => 22001,
            'metadata' => ['data' => ['vehiclePlate' => 'AAA111']],
        ]);

        $result = Order::withoutSyncingToSearch(
            fn () => new AddObservationsAction(
                $order,
                $user,
                'Vehículo con daños en parachoque trasero',
                'Observación registrada al momento del ingreso',
            )->execute()
        );

        $this->assertEquals('Vehículo con daños en parachoque trasero', $result->metadata['data']['observations']);

        $log = Activity::where('subject_id', $order->id)
            ->where('subject_type', Order::class)
            ->where('description', 'add-observations')
            ->first();

        $this->assertNotNull($log);
        $this->assertEquals('Vehículo con daños en parachoque trasero', $log->properties['changes']['observations']['new']);
        $this->assertEquals('Observación registrada al momento del ingreso', $log->properties['reason']);
    }

    public function testItReplacesExistingObservationAndKeepsEvidenceOutOfOrderImages(): void
    {
        $app = app(Apps::class);
        $user = Auth::user();

        $order = $this->createTestOrder($app, $user, [
            'order_number' => 22002,
            'metadata' => [
                'data' => [
                    'vehiclePlate' => 'BBB222',
                    'observations' => 'Observación anterior',
                    'images' => ['https://s3.example.com/old.jpg'],
                ],
            ],
        ]);

        $result = Order::withoutSyncingToSearch(
            fn () => new AddObservationsAction(
                $order,
                $user,
                'Observación actualizada',
                'Corrección de observación previa',
                ['https://s3.example.com/evidence.jpg'],
            )->execute()
        );

        $this->assertEquals('Observación actualizada', $result->metadata['data']['observations']);
        $this->assertEquals(['https://s3.example.com/old.jpg'], $result->metadata['data']['images']);

        $log = Activity::where('subject_id', $order->id)
            ->where('subject_type', Order::class)
            ->where('description', 'add-observations')
            ->first();

        $this->assertEquals('Observación anterior', $log->properties['changes']['observations']['old']);
        $this->assertEquals('Observación actualizada', $log->properties['changes']['observations']['new']);
        $this->assertEquals(['https://s3.example.com/evidence.jpg'], $log->properties['evidence']);
    }

    public function testItBlocksObservationsOnFinalStatus(): void
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
            'order_number' => 22003,
            'order_status_id' => $releasedStatus->id,
            'metadata' => ['data' => ['vehiclePlate' => 'CCC333']],
        ]);

        $this->expectException(ValidationException::class);

        Order::withoutSyncingToSearch(
            fn () => new AddObservationsAction($order, $user, 'should fail', 'test')->execute()
        );
    }
}
