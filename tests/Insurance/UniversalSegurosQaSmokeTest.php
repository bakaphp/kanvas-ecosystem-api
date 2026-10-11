<?php

declare(strict_types=1);

namespace Tests\Insurance;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\UniversalSeguros\Enums\ConfigurationEnum;
use Kanvas\Connectors\UniversalSeguros\Enums\EnvironmentEnum;
use Kanvas\Connectors\UniversalSeguros\Providers\UniversalSegurosProvider;
use Kanvas\Connectors\UniversalSeguros\Services\UniversalSegurosService;
use Kanvas\Insurance\DataTransferObject\CanonicalQuoteInput;
use Tests\TestCase;

class UniversalSegurosQaSmokeTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = [null, 'commerce', 'ecosystem'];

    private function env(string $name): ?string
    {
        $value = $_ENV[$name] ?? $_SERVER[$name] ?? getenv($name);

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function provider(): UniversalSegurosProvider
    {
        $clientId = $this->env('TEST_UNIVERSAL_SEGUROS_CLIENT_ID');
        $clientSecret = $this->env('TEST_UNIVERSAL_SEGUROS_CLIENT_SECRET');

        if ($this->env('TEST_UNIVERSAL_SEGUROS_LIVE') !== '1' || $clientId === null || $clientSecret === null) {
            $this->markTestSkipped(
                'Set TEST_UNIVERSAL_SEGUROS_LIVE=1 with TEST_UNIVERSAL_SEGUROS_CLIENT_ID and TEST_UNIVERSAL_SEGUROS_CLIENT_SECRET to hit Universal QA.'
            );
        }

        $company = $this->createUser()->getCurrentCompany();
        $company->set(ConfigurationEnum::ENVIRONMENT->value, EnvironmentEnum::QA->value);
        $company->set(ConfigurationEnum::CLIENT_ID->value, $clientId);
        $company->set(ConfigurationEnum::CLIENT_SECRET->value, $clientSecret);

        $app = app(Apps::class);

        return new UniversalSegurosProvider($app, $company, new UniversalSegurosService($app, $company));
    }

    public function testQaServesTheVehicleCatalogWithoutWritingAnything(): void
    {
        $catalog = $this->provider()->getCatalog('vehicle_models');

        $this->assertNotEmpty($catalog);
    }

    public function testQaQuotesAVehicleWithoutEmittingOrCharging(): void
    {
        $modelId = $this->env('TEST_UNIVERSAL_SEGUROS_MODEL_ID');

        if ($modelId === null) {
            $this->markTestSkipped('Set TEST_UNIVERSAL_SEGUROS_MODEL_ID to a QA vehicle model id to quote.');
        }

        $provider = $this->provider();
        $input = CanonicalQuoteInput::fromMultiple(
            app(Apps::class),
            $this->createUser()->getCurrentCompany(),
            [
                'category' => 'full',
                'vehicle' => [
                    'brand' => 'Toyota',
                    'model' => 'Corolla',
                    'year' => 2022,
                    'fuel_type' => 'gasoline_diesel',
                    'estimated_value' => 1000000,
                ],
            ]
        );

        $quote = $provider->quoteCanonical($input, 'A-PA', $modelId);

        $this->assertNotSame('', $quote->quoteNumber);
        $this->assertNotNull($quote->total);
    }
}
