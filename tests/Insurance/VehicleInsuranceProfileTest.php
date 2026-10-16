<?php

declare(strict_types=1);

namespace Tests\Insurance;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Insurance\Actions\StampVehicleInsuranceProfileAction;
use Kanvas\Insurance\DataTransferObject\CanonicalVehicle;
use Kanvas\Insurance\DataTransferObject\VehicleInsuranceProfile;
use Kanvas\Insurance\Enums\VehicleFuelTypeEnum;
use Kanvas\Inventory\Products\Actions\CreateProductAction;
use Kanvas\Inventory\Products\DataTransferObject\Product as ProductDto;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Inventory\ProductsTypes\Models\ProductsTypes;
use Tests\TestCase;

class VehicleInsuranceProfileTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = [null, 'commerce', 'insurance', 'ecosystem'];

    private function vehicle(string $brand = 'Toyota', string $model = 'Corolla', int $year = 2022): Products
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $type = ProductsTypes::firstOrCreate(
            ['slug' => 'vehicle', 'apps_id' => $app->getId(), 'companies_id' => $company->getId()],
            ['name' => 'Vehicle', 'users_id' => $user->getId(), 'weight' => 0]
        );

        return new CreateProductAction(
            new ProductDto(
                app: $app,
                company: $company,
                user: $user,
                name: "{$brand} {$model} " . uniqid(),
                description: 'vehicle',
                productsType: $type,
                sku: 'veh-' . uniqid(),
                attributes: [
                    ['name' => 'brand', 'value' => $brand],
                    ['name' => 'model', 'value' => $model],
                    ['name' => 'year', 'value' => (string) $year],
                ],
            ),
            $user,
        )->setRunWorkflow(false)->execute();
    }

    public function testFuelTypesMapToUniversalAllowedValues(): void
    {
        $this->assertSame('Gas', VehicleFuelTypeEnum::GAS->universalValue());
        $this->assertSame('Gasolina / Diesel', VehicleFuelTypeEnum::GASOLINE_DIESEL->universalValue());
        $this->assertSame('Vehículo Electrico', VehicleFuelTypeEnum::ELECTRIC->universalValue());
        $this->assertCount(3, VehicleFuelTypeEnum::cases());
    }

    public function testStampedProfileRoundTripsThroughTheVehicleAttributes(): void
    {
        $vehicle = $this->vehicle();

        new StampVehicleInsuranceProfileAction(
            $vehicle,
            new VehicleInsuranceProfile(
                fuelType: VehicleFuelTypeEnum::ELECTRIC,
                isZeroKm: true,
                estimatedValue: 1250000.5,
            ),
        )->execute();

        $profile = VehicleInsuranceProfile::fromProduct($vehicle->fresh());

        $this->assertSame(VehicleFuelTypeEnum::ELECTRIC, $profile->fuelType);
        $this->assertTrue($profile->isZeroKm);
        $this->assertSame(1250000.5, $profile->estimatedValue);
    }

    public function testStampingFalseZeroKmAndPartialProfilesLeaveOtherFieldsAlone(): void
    {
        $vehicle = $this->vehicle();

        new StampVehicleInsuranceProfileAction(
            $vehicle,
            new VehicleInsuranceProfile(fuelType: VehicleFuelTypeEnum::GAS, isZeroKm: false),
        )->execute();
        new StampVehicleInsuranceProfileAction(
            $vehicle,
            new VehicleInsuranceProfile(estimatedValue: 900000.0),
        )->execute();

        $profile = VehicleInsuranceProfile::fromProduct($vehicle->fresh());

        $this->assertSame(VehicleFuelTypeEnum::GAS, $profile->fuelType);
        $this->assertFalse($profile->isZeroKm);
        $this->assertSame(900000.0, $profile->estimatedValue);
    }

    public function testAVehicleWithoutInsuranceFieldsReadsAsEmptyProfile(): void
    {
        $profile = VehicleInsuranceProfile::fromProduct($this->vehicle());

        $this->assertNull($profile->fuelType);
        $this->assertNull($profile->isZeroKm);
        $this->assertNull($profile->estimatedValue);
    }

    public function testCanonicalVehicleReadsBrandModelYearAndNormalizesKeys(): void
    {
        $canonical = CanonicalVehicle::fromProduct($this->vehicle('Citroën', 'C-Elysée 1.6', 2019));

        $this->assertSame(2019, $canonical->year);
        $this->assertSame('citroen', $canonical->brandKey());
        $this->assertSame('celysee16', $canonical->modelKey());
    }
}
