<?php

declare(strict_types=1);

namespace Tests\Insurance;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Kanvas\Connectors\Humano\Providers\HumanoProvider;
use Kanvas\Connectors\Humano\Services\HumanoService;
use Kanvas\Connectors\UniversalSeguros\DataTransferObject\QuoteRequest;
use Kanvas\Connectors\UniversalSeguros\Enums\ConfigurationEnum;
use Kanvas\Connectors\UniversalSeguros\Providers\UniversalSegurosProvider;
use Kanvas\Connectors\UniversalSeguros\Services\UniversalSegurosService;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Insurance\Contracts\CanonicalQuoteProviderInterface;
use Kanvas\Insurance\DataTransferObject\CanonicalQuoteInput;
use Kanvas\Insurance\DataTransferObject\CanonicalVehicle;
use Kanvas\Insurance\DataTransferObject\VehicleInsuranceProfile;
use Kanvas\Insurance\Enums\InsuranceCoverageCategoryEnum;
use Kanvas\Insurance\Enums\VehicleFuelTypeEnum;
use Mockery;
use Tests\TestCase;

class CanonicalQuoteAdaptersTest extends TestCase
{
    private function input(?VehicleInsuranceProfile $profile = null, ?int $year = 2022, array $underwriting = []): CanonicalQuoteInput
    {
        $app = Mockery::mock(AppInterface::class);
        $app->shouldReceive('getId')->andReturn(1);
        $company = Mockery::mock(CompanyInterface::class);
        $company->shouldReceive('getId')->andReturn(2);

        return new CanonicalQuoteInput(
            app: $app,
            company: $company,
            vehicle: new CanonicalVehicle('Toyota', 'Corolla', $year),
            profile: $profile ?? new VehicleInsuranceProfile(VehicleFuelTypeEnum::GAS, true, 850000.4),
            category: InsuranceCoverageCategoryEnum::FULL,
            underwriting: $underwriting,
        );
    }

    private function universal(UniversalSegurosService $service): UniversalSegurosProvider
    {
        $company = Mockery::mock(CompanyInterface::class);
        $company->shouldReceive('getId')->andReturn(2);
        $company->shouldReceive('get')->with(ConfigurationEnum::SCOPES->value)->andReturn('');

        return new UniversalSegurosProvider(
            app: Mockery::mock(AppInterface::class),
            company: $company,
            service: $service,
        );
    }

    public function testRequestsDifferingOnlyInIpShareASignatureButOtherFactsDoNot(): void
    {
        $this->assertSame(
            $this->input(underwriting: ['ip_address' => '10.0.0.1', 'age' => '30'])->signature(),
            $this->input(underwriting: ['ip_address' => '10.0.0.2', 'age' => '30'])->signature()
        );
        $this->assertNotSame(
            $this->input(underwriting: ['age' => '30'])->signature(),
            $this->input(underwriting: ['age' => '31'])->signature()
        );
    }

    public function testHumanoIsNotSearchableUntilACrosswalkExists(): void
    {
        $provider = new HumanoProvider(
            app: Mockery::mock(AppInterface::class),
            company: Mockery::mock(CompanyInterface::class),
            service: Mockery::mock(HumanoService::class),
        );

        $this->assertNotInstanceOf(CanonicalQuoteProviderInterface::class, $provider);
    }

    public function testBirthDateMustBeARealDate(): void
    {
        $this->expectException(ValidationException::class);

        CanonicalQuoteInput::fromMultiple(
            Mockery::mock(AppInterface::class),
            Mockery::mock(CompanyInterface::class),
            [
                'category' => 'full',
                'vehicle' => ['brand' => 'Toyota', 'model' => 'Corolla'],
                'underwriting' => ['birth_date' => '31/02/1990'],
            ]
        );
    }

    public function testUniversalMapsTheCanonicalVehicleToItsOwnShape(): void
    {
        $service = Mockery::mock(UniversalSegurosService::class);
        $service->shouldReceive('quote')
            ->once()
            ->withArgs(function (QuoteRequest $request): bool {
                $vehiculo = $request->toArray()['data']['vehiculo'];

                return $request->producto === 'A-PC'
                    && $vehiculo['idModelo'] === 42
                    && $vehiculo['anio'] === 2022
                    && $vehiculo['esCeroKm'] === true
                    && $vehiculo['combustible'] === 'Gas'
                    && $vehiculo['valor'] === 850000
                    && $vehiculo['sumaAsegurada'] === 850000;
            })
            ->andReturn(['numeroCotizacion' => 'U-1', 'prima' => 100, 'total' => 116]);

        $quote = $this->universal($service)->quoteCanonical($this->input(), 'A-PC', '42');

        $this->assertTrue($this->universal($service)->requiresVehicleModelId());
        $this->assertSame('U-1', $quote->quoteNumber);
    }

    public function testUniversalNamesEveryCanonicalFieldItIsMissingInsteadOfGuessing(): void
    {
        $service = Mockery::mock(UniversalSegurosService::class);
        $service->shouldNotReceive('quote');

        try {
            $this->universal($service)->quoteCanonical(
                $this->input(new VehicleInsuranceProfile(), year: null),
                'A-PL',
                null
            );
            $this->fail('Expected a ValidationException');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('vehicle model', $e->getMessage());
            $this->assertStringContainsString('year', $e->getMessage());
            $this->assertStringContainsString('fuel_type', $e->getMessage());
            $this->assertStringContainsString('estimated_value', $e->getMessage());
        }
    }

    public function testHumanoSendsWhatCanonicalKnowsAndNamesTheRestAsMissing(): void
    {
        $service = Mockery::mock(HumanoService::class);
        $service->shouldNotReceive('quote');
        $company = Mockery::mock(CompanyInterface::class);
        $company->shouldReceive('getId')->andReturn(2);
        $provider = new HumanoProvider(
            app: Mockery::mock(AppInterface::class),
            company: $company,
            service: $service,
        );

        $this->assertFalse($provider->requiresVehicleModelId());

        try {
            $provider->quoteCanonical(
                $this->input(underwriting: ['ip_address' => '10.0.0.1', 'age' => '30', 'sex' => 'M']),
                'mi_auto_full',
                null
            );
            $this->fail('Expected a ValidationException');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('version', $e->getMessage());
            $this->assertStringContainsString('uso', $e->getMessage());
            $this->assertStringNotContainsString('marca', $e->getMessage());
            $this->assertStringNotContainsString('valor_vehiculo', $e->getMessage());
            $this->assertStringNotContainsString('edad', $e->getMessage());
            $this->assertStringNotContainsString('sexo', $e->getMessage());
        }
    }
}
