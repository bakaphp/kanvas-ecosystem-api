<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\ChromeData;

use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\ChromeData\Client;
use Kanvas\Connectors\ChromeData\Enums\ConfigurationEnum;
use Mockery;
use SoapClient;
use Tests\TestCase;

final class ChromeDataClientTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function testDescribeVehicleByVin(): void
    {
        $app = app(Apps::class);
        $app->set(ConfigurationEnum::ACCOUNT_NUMBER->value, 'test_account');
        $app->set(ConfigurationEnum::ACCOUNT_SECRET->value, 'test_secret');
        $app->set(ConfigurationEnum::COUNTRY->value, 'US');
        $app->set(ConfigurationEnum::LANGUAGE->value, 'en');

        // Mock SOAP client
        $mockSoapClient = Mockery::mock(SoapClient::class);

        // Mock SOAP response
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
            'bestStyleName' => '4dr SUV',
            'style' => (object) [
                'id' => 449991,
                'name' => 'ES 4dr SUV',
                'drivetrain' => 'FWD',
                'passDoors' => 4,
                'stockImage' => (object) [
                    'url' => 'https://example.com/stock-image.jpg',
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
            'exteriorColor' => [
                (object) [
                    'colorCode' => 'X42',
                    'colorName' => 'Rally Red Metallic',
                    'rgbValue' => 'CC0000',
                ],
            ],
            'interiorColor' => [
                (object) [
                    'colorCode' => 'Y20',
                    'colorName' => 'Black',
                    'rgbValue' => '000000',
                ],
            ],
            'responseStatus' => (object) [
                'responseCode' => 'Success',
            ],
        ];

        $mockSoapClient->shouldReceive('__soapCall')
            ->once()
            ->with('describeVehicle', Mockery::type('array'))
            ->andReturn($mockResponse);

        // Inject mock via reflection
        $client = new Client($app);
        $reflection = new \ReflectionClass($client);
        $property = $reflection->getProperty('client');
        $property->setAccessible(true);
        $property->setValue($client, $mockSoapClient);

        // Execute test
        $result = $client->describeVehicleByVin('JA4ARUAU6TU001602');

        $this->assertIsObject($result);
        $this->assertEquals('JA4ARUAU6TU001602', $result->vinDescription->vin);
        $this->assertEquals(2026, $result->modelYear);
        $this->assertEquals('Mitsubishi', $result->bestMakeName);
        $this->assertEquals('Outlander Sport', $result->bestModelName);
    }

    public function testGetModelYears(): void
    {
        $app = app(Apps::class);
        $app->set(ConfigurationEnum::ACCOUNT_NUMBER->value, 'test_account');
        $app->set(ConfigurationEnum::ACCOUNT_SECRET->value, 'test_secret');

        $mockSoapClient = Mockery::mock(SoapClient::class);

        $mockResponse = (object) [
            'modelYear' => [2026, 2025, 2024, 2023, 2022],
        ];

        $mockSoapClient->shouldReceive('__soapCall')
            ->once()
            ->with('getModelYears', Mockery::type('array'))
            ->andReturn($mockResponse);

        $client = new Client($app);
        $reflection = new \ReflectionClass($client);
        $property = $reflection->getProperty('client');
        $property->setAccessible(true);
        $property->setValue($client, $mockSoapClient);

        $result = $client->getModelYears();

        $this->assertIsObject($result);
        $this->assertIsArray($result->modelYear);
        $this->assertContains(2026, $result->modelYear);
        $this->assertContains(2025, $result->modelYear);
    }
}
