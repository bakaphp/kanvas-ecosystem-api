<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Movipass;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Auth;
use Kanvas\Activities\Models\Activity;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Movipass\Actions\Corrections\CorrectVehicleDataAction;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Regions\Models\Regions;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Users\Models\Users;
use Tests\TestCase;

final class CorrectVehicleDataActionTest extends TestCase
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

    public function testItCorrectsModelAndColor(): void
    {
        $app = app(Apps::class);
        $user = Auth::user();

        $order = $this->createTestOrder($app, $user, [
            'order_number' => 22345,
            'metadata' => ['data' => [
                'vehiclePlate' => 'ABC123',
                'vehicleBrand' => 'Toyota',
                'vehicleModel' => 'Corola',
                'vehicleColor' => 'blanco',
            ]],
            'reference' => 'Toyota / ABC123 - #22345',
        ]);

        $result = Order::withoutSyncingToSearch(
            fn () => new CorrectVehicleDataAction(
                $order,
                $user,
                ['model' => 'Corolla', 'color' => 'gris'],
                'Modelo y color mal digitados al ingresar el vehículo',
            )->execute()
        );

        $this->assertEquals('Corolla', $result->metadata['data']['vehicleModel']);
        $this->assertEquals('gris', $result->metadata['data']['vehicleColor']);
        $this->assertEquals('Toyota', $result->metadata['data']['vehicleBrand']);
        $this->assertEquals('Toyota / ABC123 - #22345', $result->reference);

        $log = Activity::where('subject_id', $order->id)
            ->where('subject_type', Order::class)
            ->where('description', 'correct-vehicle-data')
            ->first();

        $this->assertNotNull($log);
        $this->assertEquals('Corola', $log->properties['changes']['vehicleModel']['old']);
        $this->assertEquals('Corolla', $log->properties['changes']['vehicleModel']['new']);
        $this->assertEquals('blanco', $log->properties['changes']['vehicleColor']['old']);
        $this->assertEquals('gris', $log->properties['changes']['vehicleColor']['new']);
        $this->assertArrayNotHasKey('vehicleBrand', $log->properties['changes']);
    }

    public function testItSyncsReferenceWhenBrandChanges(): void
    {
        $app = app(Apps::class);
        $user = Auth::user();

        $order = $this->createTestOrder($app, $user, [
            'order_number' => 22346,
            'metadata' => ['data' => ['vehiclePlate' => 'L083926', 'vehicleBrand' => 'Isuzu']],
            'reference' => 'Isuzu / L083926 - #22346',
        ]);

        $result = Order::withoutSyncingToSearch(
            fn () => new CorrectVehicleDataAction(
                $order,
                $user,
                ['brand' => 'Hyundai'],
                'Marca corregida contra la matrícula',
            )->execute()
        );

        $this->assertEquals('Hyundai', $result->metadata['data']['vehicleBrand']);
        $this->assertEquals('Hyundai / L083926 - #22346', $result->reference);
    }

    public function testItRejectsWhenNothingChanges(): void
    {
        $app = app(Apps::class);
        $user = Auth::user();

        $order = $this->createTestOrder($app, $user, [
            'order_number' => 22347,
            'metadata' => ['data' => ['vehiclePlate' => 'NOP001', 'vehicleColor' => 'negro']],
            'reference' => 'Kia / NOP001 - #22347',
        ]);

        $this->expectException(ValidationException::class);

        Order::withoutSyncingToSearch(
            fn () => new CorrectVehicleDataAction(
                $order,
                $user,
                ['color' => 'negro'],
                'sin cambios reales',
            )->execute()
        );
    }

    public function testItIgnoresBlankValuesAndKeepsEvidenceOutOfMetadata(): void
    {
        $app = app(Apps::class);
        $user = Auth::user();

        $order = $this->createTestOrder($app, $user, [
            'order_number' => 22348,
            'metadata' => ['data' => [
                'vehiclePlate' => 'EVI001',
                'vehicleBrand' => 'Honda',
                'vehicleColor' => 'azul',
                'images' => ['https://s3.example.com/existing.jpg'],
            ]],
            'reference' => 'Honda / EVI001 - #22348',
        ]);

        $result = Order::withoutSyncingToSearch(
            fn () => new CorrectVehicleDataAction(
                $order,
                $user,
                ['brand' => '   ', 'color' => 'rojo'],
                'Color corregido con foto de evidencia',
                ['https://s3.example.com/evidence1.jpg'],
            )->execute()
        );

        $this->assertEquals('Honda', $result->metadata['data']['vehicleBrand']);
        $this->assertEquals('rojo', $result->metadata['data']['vehicleColor']);
        $this->assertEquals(['https://s3.example.com/existing.jpg'], $result->metadata['data']['images']);

        $log = Activity::where('subject_id', $order->id)
            ->where('subject_type', Order::class)
            ->where('description', 'correct-vehicle-data')
            ->first();

        $this->assertEquals(['https://s3.example.com/evidence1.jpg'], $log->properties['evidence']);
        $this->assertArrayNotHasKey('vehicleBrand', $log->properties['changes']);
    }
}
