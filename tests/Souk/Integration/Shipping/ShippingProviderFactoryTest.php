<?php

declare(strict_types=1);

namespace Tests\Souk\Integration\Shipping;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use InvalidArgumentException;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Souk\Shipping\Contracts\ShippingRateProviderInterface;
use Kanvas\Souk\Shipping\DataTransferObject\ShipmentRequest;
use Kanvas\Souk\Shipping\Providers\ShippingProviderFactory;
use Kanvas\Souk\Shipping\RateCards\RateCardShippingProvider;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\Integrations\Models\IntegrationsCompany;
use Kanvas\Workflow\Models\Integrations;
use Tests\Souk\Concerns\ImportsShippingRateCards;
use Tests\TestCase;

final class ShippingProviderFactoryTest extends TestCase
{
    use DatabaseTransactions;
    use ImportsShippingRateCards;

    protected $connectionsToTransact = [null, 'workflow', 'ecosystem', 'commerce'];

    protected function setUp(): void
    {
        parent::setUp();

        app()->bind('shipping_provider.fake', function ($app, array $params) {
            return new class ($params['app'], $params['company']) implements ShippingRateProviderInterface {
                public function __construct(
                    private readonly AppInterface $app,
                    private readonly CompanyInterface $company,
                ) {
                }

                public function name(): string
                {
                    return 'fake';
                }

                public function integration(): ?IntegrationsEnum
                {
                    return IntegrationsEnum::YUSEN;
                }

                public function supports(ShipmentRequest $request): bool
                {
                    return true;
                }

                public function quote(ShipmentRequest $request): array
                {
                    return [];
                }
            };
        });

        app()->instance(ShippingProviderFactory::API_PROVIDER_NAMES, ['fake']);
    }

    public function testForCompanyReturnsTheApiProviderEnabledForTheCompany(): void
    {
        $company = Companies::factory()->create();
        $this->connectIntegration($company, IntegrationsEnum::YUSEN);

        $providers = ShippingProviderFactory::forCompany(app(Apps::class), $company);

        $this->assertCount(1, $providers);
        $this->assertSame('fake', $providers[0]->name());
    }

    public function testForCompanyExcludesADisabledIntegration(): void
    {
        $company = Companies::factory()->create();
        $this->connectIntegration($company, IntegrationsEnum::YUSEN, active: false);

        $providers = ShippingProviderFactory::forCompany(app(Apps::class), $company);

        $this->assertSame([], $providers);
    }

    public function testForCompanyExcludesAProviderWithNoIntegrationRowAtAll(): void
    {
        $company = Companies::factory()->create();

        $providers = ShippingProviderFactory::forCompany(app(Apps::class), $company);

        $this->assertSame([], $providers);
    }

    public function testForCompanyAppendsRateCardProvidersFromTheImportedCardsAfterTheApiProviders(): void
    {
        $company = Companies::factory()->create();
        $this->connectIntegration($company, IntegrationsEnum::YUSEN);
        $this->importRateCards($company, [
            'inposdom' => [
                'zones' => ['US' => 'z1'],
                'services' => [
                    'ems' => [
                        'name' => 'EMS',
                        'currency' => 'DOP',
                        'zones' => ['z1' => ['rates' => [['max_grams' => 1000, 'amount' => 100]]]],
                    ],
                ],
            ],
        ]);

        $providers = ShippingProviderFactory::forCompany(app(Apps::class), $company);

        $this->assertCount(2, $providers);
        $this->assertSame('fake', $providers[0]->name());
        $this->assertInstanceOf(RateCardShippingProvider::class, $providers[1]);
        $this->assertSame('inposdom', $providers[1]->name());
    }

    public function testForCompanyWithoutRateCardConfigHasNoRateCardProvider(): void
    {
        $company = Companies::factory()->create();

        $providers = ShippingProviderFactory::forCompany(app(Apps::class), $company);

        $this->assertSame([], $providers);
    }

    public function testMakeThrowsForAnUnboundName(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("No shipping provider registered for 'unbound-carrier'.");

        ShippingProviderFactory::make('unbound-carrier', app(Apps::class), Companies::factory()->create());
    }

    public function testMakeReturnsAFreshInstancePerCall(): void
    {
        $app = app(Apps::class);
        $company = Companies::factory()->create();

        $first = ShippingProviderFactory::make('fake', $app, $company);
        $second = ShippingProviderFactory::make('fake', $app, $company);

        $this->assertNotSame($first, $second);
    }

    private function connectIntegration(Companies $company, IntegrationsEnum $integration, bool $active = true): void
    {
        $integrationRow = Integrations::firstOrCreate(
            ['name' => $integration->value],
            [
                'apps_id' => 0,
                'handler' => 'Kanvas\\Connectors\\Fixture\\Handlers\\FixtureHandler',
            ]
        );

        $link = IntegrationsCompany::create([
            'companies_id' => $company->getId(),
            'integrations_id' => $integrationRow->getId(),
            'status_id' => 1,
            'region_id' => 1,
        ]);
        $link->is_active = $active ? 1 : 0;
        $link->is_deleted = 0;
        $link->saveQuietly();
    }
}
