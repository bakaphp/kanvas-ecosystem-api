<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\ChromeData;

use Illuminate\Support\Facades\Redis;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\ChromeData\Client;
use Kanvas\Connectors\ChromeData\DataTransferObject\VehicleData;
use Kanvas\Connectors\ChromeData\Enums\ConfigurationEnum;
use Kanvas\Connectors\ChromeData\Services\VehicleService;
use Mockery;
use Tests\TestCase;

final class VehicleServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function testGetVehicleInfoByVinReturnsDto(): void
    {
        $app = app(Apps::class);
        $app->set(ConfigurationEnum::ACCOUNT_NUMBER->value, 'test_account');
        $app->set(ConfigurationEnum::ACCOUNT_SECRET->value, 'test_secret');

        // Clear cache before test
        Redis::del('chromedata:vin:JA4ARUAU6TU001602');

        // Mock the Client
        $mockClient = Mockery::mock(Client::class);

        $mockResponse = (object) [
            'vinDescription' => (object) [
                'vin' => 'JA4ARUAU6TU001602',
                'modelYear' => 2026,
                'bodyType' => 'SUV',
            ],
            'modelYear' => 2026,
            'bestMakeName' => 'Mitsubishi',
            'bestModelName' => 'Outlander Sport',
            'bestTrimName' => 'ES',
            'bestStyleName' => 'ES 4dr SUV',
            'style' => (object) [
                'id' => 449991,
                'name' => 'ES 4dr SUV',
                'drivetrain' => 'FWD',
                'passDoors' => 4,
                'stockImage' => (object) [
                    'url' => 'https://cdn.chromedata.com/stock-image.jpg',
                ],
                'basePrice' => (object) [
                    'msrp' => 25000,
                    'invoice' => 23000,
                    'destination' => 1200,
                ],
            ],
            'engine' => (object) [
                'cylinders' => 4,
                'engineType' => (object) ['_' => 'Inline'],
                'fuelType' => (object) ['_' => 'Gasoline'],
                'displacement' => (object) [
                    'value' => (object) ['_' => '2.0L'],
                ],
                'horsepower' => (object) ['value' => 148],
                'netTorque' => (object) ['value' => 145],
            ],
            'exteriorColor' => [],
            'interiorColor' => [],
            'responseStatus' => (object) ['responseCode' => 'Success'],
        ];

        $mockClient->shouldReceive('describeVehicleByVin')
            ->once()
            ->with('JA4ARUAU6TU001602', [])
            ->andReturn($mockResponse);

        // Inject mock
        $service = new VehicleService($app);
        $reflection = new \ReflectionClass($service);
        $property = $reflection->getProperty('client');
        $property->setAccessible(true);
        $property->setValue($service, $mockClient);

        // Execute
        $result = $service->getVehicleInfoByVin('JA4ARUAU6TU001602', skipCache: true);

        $this->assertInstanceOf(VehicleData::class, $result);
        $this->assertEquals('JA4ARUAU6TU001602', $result->vin);
        $this->assertEquals(2026, $result->year);
        $this->assertEquals('Mitsubishi', $result->make);
        $this->assertEquals('Outlander Sport', $result->model);
        $this->assertEquals('ES', $result->trim);
        $this->assertEquals('https://cdn.chromedata.com/stock-image.jpg', $result->stockImage);
        $this->assertInstanceOf(\Kanvas\Connectors\ChromeData\DataTransferObject\EngineData::class, $result->engine);
        $this->assertEquals(4, $result->engine->cylinders);
        $this->assertEquals('Inline', $result->engine->type);
        $this->assertEquals('Gasoline', $result->engine->fuelType);
    }

    public function testGetVehicleInfoByVinUsesCache(): void
    {
        $app = app(Apps::class);
        $app->set(ConfigurationEnum::ACCOUNT_NUMBER->value, 'test_account');
        $app->set(ConfigurationEnum::ACCOUNT_SECRET->value, 'test_secret');

        $cacheKey = 'chromedata:vin:TESTVIN123';
        $cachedData = [
            'vin' => 'TESTVIN123',
            'year' => 2025,
            'make' => 'Toyota',
            'model' => 'Camry',
            'trim' => 'LE',
            'styleName' => 'LE 4dr Sedan',
            'bodyStyle' => 'Sedan',
            'driveTrain' => 'FWD',
            'passDoors' => 4,
            'stockImage' => 'https://example.com/cached-image.jpg',
            'engine' => null,
            'exteriorColors' => [],
            'interiorColors' => [],
            'basePrice' => null,
            'styles' => [],
            'responseStatus' => 'Success',
        ];

        // Set cache
        Redis::setex($cacheKey, 259200, json_encode($cachedData));

        // Mock should NOT be called since we're using cache
        $mockClient = Mockery::mock(Client::class);
        $mockClient->shouldNotReceive('describeVehicleByVin');

        $service = new VehicleService($app);
        $reflection = new \ReflectionClass($service);
        $property = $reflection->getProperty('client');
        $property->setAccessible(true);
        $property->setValue($service, $mockClient);

        $result = $service->getVehicleInfoByVin('TESTVIN123');

        $this->assertInstanceOf(VehicleData::class, $result);
        $this->assertEquals('TESTVIN123', $result->vin);
        $this->assertEquals('Toyota', $result->make);
        $this->assertEquals('Camry', $result->model);
        $this->assertEquals('https://example.com/cached-image.jpg', $result->stockImage);

        // Cleanup
        Redis::del($cacheKey);
    }

    public function testGetModelYears(): void
    {
        $app = app(Apps::class);
        $app->set(ConfigurationEnum::ACCOUNT_NUMBER->value, 'test_account');
        $app->set(ConfigurationEnum::ACCOUNT_SECRET->value, 'test_secret');

        $mockClient = Mockery::mock(Client::class);

        $mockResponse = (object) [
            'modelYear' => [2026, 2025, 2024, 2023, 2022],
        ];

        $mockClient->shouldReceive('getModelYears')
            ->once()
            ->andReturn($mockResponse);

        $service = new VehicleService($app);
        $reflection = new \ReflectionClass($service);
        $property = $reflection->getProperty('client');
        $property->setAccessible(true);
        $property->setValue($service, $mockClient);

        $result = $service->getModelYears();

        $this->assertIsArray($result);
        $this->assertContains(2026, $result);
        $this->assertContains(2025, $result);
    }

    public function testGetStockImageByVinWithoutMakeModelYear(): void
    {
        $app = app(Apps::class);
        $app->set(ConfigurationEnum::ACCOUNT_NUMBER->value, 'test_account');
        $app->set(ConfigurationEnum::ACCOUNT_SECRET->value, 'test_secret');

        // Clear cache
        Redis::del('chromedata:vin:TESTVIN789');

        $mockClient = Mockery::mock(Client::class);

        $mockResponse = (object) [
            'vinDescription' => (object) ['vin' => 'TESTVIN789', 'modelYear' => 2025],
            'modelYear' => 2025,
            'bestMakeName' => 'Honda',
            'bestModelName' => 'Civic',
            'style' => (object) [
                'stockImage' => (object) ['url' => 'https://cdn.chromedata.com/civic.jpg'],
            ],
            'engine' => (object) [],
            'exteriorColor' => [],
            'interiorColor' => [],
        ];

        $mockClient->shouldReceive('describeVehicleByVin')
            ->once()
            ->with('TESTVIN789', [])
            ->andReturn($mockResponse);

        $service = new VehicleService($app);
        $reflection = new \ReflectionClass($service);
        $property = $reflection->getProperty('client');
        $property->setAccessible(true);
        $property->setValue($service, $mockClient);

        $result = $service->getStockImageByVin('TESTVIN789', skipCache: true);

        $this->assertEquals('https://cdn.chromedata.com/civic.jpg', $result);

        // Cleanup
        Redis::del('chromedata:vin:TESTVIN789');
    }

    public function testGetStockImageByVinWithMakeModelYear(): void
    {
        $app = app(Apps::class);
        $app->set(ConfigurationEnum::ACCOUNT_NUMBER->value, 'test_account');
        $app->set(ConfigurationEnum::ACCOUNT_SECRET->value, 'test_secret');

        $vin = 'VIN123456';
        $make = 'Toyota';
        $model = 'Camry';
        $year = 2024;

        // Clear caches
        Redis::del('chromedata:vin:' . $vin);
        Redis::del('chromedata2:stock:mmy:toyota:camry:2024');

        $mockClient = Mockery::mock(Client::class);

        $mockResponse = (object) [
            'vinDescription' => (object) ['vin' => $vin, 'modelYear' => $year],
            'modelYear' => $year,
            'bestMakeName' => $make,
            'bestModelName' => $model,
            'style' => (object) [
                'stockImage' => (object) ['url' => 'https://cdn.chromedata.com/camry-2024.jpg'],
            ],
            'engine' => (object) [],
            'exteriorColor' => [],
            'interiorColor' => [],
        ];

        $mockClient->shouldReceive('describeVehicleByVin')
            ->once()
            ->with($vin, [])
            ->andReturn($mockResponse);

        $service = new VehicleService($app);
        $reflection = new \ReflectionClass($service);
        $property = $reflection->getProperty('client');
        $property->setAccessible(true);
        $property->setValue($service, $mockClient);

        // First call - should hit API and cache both VIN and make/model/year
        $result = $service->getStockImageByVin($vin, $make, $model, $year, skipCache: true);

        $this->assertEquals('https://cdn.chromedata.com/camry-2024.jpg', $result);

        // Verify make/model/year cache was set
        $mmyCache = Redis::get('chromedata2:stock:mmy:toyota:camry:2024');
        $this->assertNotNull($mmyCache);
        $this->assertEquals('https://cdn.chromedata.com/camry-2024.jpg', $mmyCache);

        // Cleanup
        Redis::del('chromedata:vin:' . $vin);
        Redis::del('chromedata2:stock:mmy:toyota:camry:2024');
    }

    public function testGetStockImageByVinUsesMakeModelYearCache(): void
    {
        $app = app(Apps::class);
        $app->set(ConfigurationEnum::ACCOUNT_NUMBER->value, 'test_account');
        $app->set(ConfigurationEnum::ACCOUNT_SECRET->value, 'test_secret');

        $make = 'Ford';
        $model = 'F-150';
        $year = 2023;
        $cachedUrl = 'https://cdn.chromedata.com/f150-cached.jpg';

        // Set make/model/year cache
        Redis::setex('chromedata2:stock:mmy:ford:f-150:2023', 259200, $cachedUrl);

        // Mock should NOT be called since we have make/model/year cache
        $mockClient = Mockery::mock(Client::class);
        $mockClient->shouldNotReceive('describeVehicleByVin');

        $service = new VehicleService($app);
        $reflection = new \ReflectionClass($service);
        $property = $reflection->getProperty('client');
        $property->setAccessible(true);
        $property->setValue($service, $mockClient);

        // Should return cached result without API call
        $result = $service->getStockImageByVin('ANYVIN123', $make, $model, $year);

        $this->assertEquals($cachedUrl, $result);

        // Cleanup
        Redis::del('chromedata2:stock:mmy:ford:f-150:2023');
    }
}
