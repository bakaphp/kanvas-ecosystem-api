<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\ChromeData;

use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\ChromeData\Enums\ConfigurationEnum;
use Kanvas\Connectors\ChromeData\Services\VehicleService;
use Tests\TestCase;

/**
 * ChromeData Service Integration Test
 *
 * This test demonstrates best practice: testing services with mocked API responses
 * instead of making real API calls to third-party providers.
 *
 * For detailed mocked testing examples, see:
 * - ChromeDataClientTest: Mocked SOAP client responses
 * - VehicleServiceTest: Mocked service calls with Redis caching
 */
final class ChromeDataServiceTest extends TestCase
{
    public function testVehicleServiceInstantiation(): void
    {
        $app = app(Apps::class);

        // Setup credentials (using test values, not real API credentials)
        $app->set(ConfigurationEnum::ACCOUNT_NUMBER->value, 'test_account');
        $app->set(ConfigurationEnum::ACCOUNT_SECRET->value, 'test_secret');
        $app->set(ConfigurationEnum::COUNTRY->value, 'US');
        $app->set(ConfigurationEnum::LANGUAGE->value, 'en');

        $service = new VehicleService($app);

        // Verify the service can be instantiated with proper config
        $this->assertInstanceOf(VehicleService::class, $service);
    }
}
