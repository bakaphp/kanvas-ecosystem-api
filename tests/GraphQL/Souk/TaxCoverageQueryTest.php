<?php

declare(strict_types=1);

namespace Tests\GraphQL\Souk;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Souk\Taxes\Enums\ConfigurationEnum;
use Kanvas\Souk\Taxes\Models\TaxRegistration;
use Kanvas\Souk\Taxes\Models\TaxRoutingRule;
use Tests\TestCase;

class TaxCoverageQueryTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = ['commerce'];

    protected Apps $kanvasApp;

    public function setUp(): void
    {
        parent::setUp();
        $this->kanvasApp = app(Apps::class);
    }

    public function testDominicanRepublicCoverage(): void
    {
        $this->kanvasApp->set(ConfigurationEnum::TAX_ENGINE_ENABLED->value, true);

        TaxRegistration::query()->fromApp($this->kanvasApp)->where('jurisdiction_country', 'DO')->delete();

        TaxRegistration::create([
            'apps_id' => $this->kanvasApp->getId(),
            'jurisdiction_country' => 'DO',
            'provider' => 'manual',
            'is_active' => true,
            'effective_from' => now()->subYear()->format('Y-m-d'),
        ]);

        TaxRoutingRule::create([
            'apps_id' => $this->kanvasApp->getId(),
            'name' => 'DO Manual Rule',
            'provider' => 'manual',
            'condition_country' => 'DO',
            'priority' => 1,
            'is_active' => true,
        ]);

        $response = $this->graphQL('
            query($country_code: String!) {
                taxCoverage(country_code: $country_code) {
                    country
                    provider
                    isSupported
                    unsupportedReason
                }
            }
        ', ['country_code' => 'DO']);

        $response->assertSuccessful();
        $response->assertJson([
            'data' => [
                'taxCoverage' => [
                    'country' => 'DO',
                    'provider' => 'manual',
                    'isSupported' => true,
                ],
            ],
        ]);
    }

    public function testUsWithoutStripeAccountReturnsUnsupported(): void
    {
        $this->kanvasApp->set(ConfigurationEnum::TAX_ENGINE_ENABLED->value, true);

        TaxRegistration::query()->fromApp($this->kanvasApp)->where('jurisdiction_country', 'US-TEST')->delete();

        $response = $this->graphQL('
            query($country_code: String!) {
                taxCoverage(country_code: $country_code) {
                    country
                    provider
                    isSupported
                    unsupportedReason
                }
            }
        ', ['country_code' => 'US-TEST']);

        $response->assertSuccessful();
        $response->assertJson([
            'data' => [
                'taxCoverage' => [
                    'country' => 'US-TEST',
                    'isSupported' => false,
                ],
            ],
        ]);

        $this->assertNotNull($response->json('data.taxCoverage.unsupportedReason'));
    }
}
