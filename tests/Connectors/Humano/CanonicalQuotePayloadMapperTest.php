<?php

declare(strict_types=1);

namespace Tests\Connectors\Humano;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Illuminate\Support\Carbon;
use Kanvas\Connectors\Humano\DataTransferObject\QuoteRequest;
use Kanvas\Connectors\Humano\Enums\DatoEnum;
use Kanvas\Connectors\Humano\Enums\PlanEnum;
use Kanvas\Connectors\Humano\Services\CanonicalQuotePayloadMapper;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Insurance\DataTransferObject\CanonicalQuoteInput;
use Kanvas\Insurance\DataTransferObject\CanonicalVehicle;
use Kanvas\Insurance\DataTransferObject\ExternalVehicleReference;
use Kanvas\Insurance\DataTransferObject\VehicleInsuranceProfile;
use Kanvas\Insurance\Enums\InsuranceCoverageCategoryEnum;
use Kanvas\Insurance\Enums\VehicleFuelTypeEnum;
use Kanvas\Insurance\Enums\VehicleUsageEnum;
use Mockery;
use Tests\TestCase;

class CanonicalQuotePayloadMapperTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function underwriting(array $overrides = []): array
    {
        return array_merge([
            'ip_address' => '10.0.0.1',
            'birth_date' => '1993-08-24',
            'marital_status' => 'single',
            'sex' => 'male',
            'usage' => 'private',
            'circulation_zone' => 'santo_domingo',
        ], $overrides);
    }

    private function input(array $underwriting, ?float $value = 850000.4): CanonicalQuoteInput
    {
        $app = Mockery::mock(AppInterface::class);
        $company = Mockery::mock(CompanyInterface::class);

        return new CanonicalQuoteInput(
            app: $app,
            company: $company,
            vehicle: new CanonicalVehicle('Honda', 'Civic', 2022, 'EX'),
            profile: new VehicleInsuranceProfile(VehicleFuelTypeEnum::GASOLINE_DIESEL, false, $value),
            category: InsuranceCoverageCategoryEnum::FULL,
            underwriting: $underwriting,
        );
    }

    private function map(array $underwriting, PlanEnum $plan = PlanEnum::MI_AUTO_FULL, ?float $value = 850000.4): array
    {
        return new CanonicalQuotePayloadMapper()->payload(
            $this->input($underwriting, $value),
            $plan,
            new ExternalVehicleReference('40', '2', '2'),
        );
    }

    public function testBirthDateGoesOutAsDayMonthYear(): void
    {
        $payload = $this->map($this->underwriting());

        $this->assertSame('24-08-1993', $payload['fecha_nacimiento']);
    }

    public function testAgeIsDerivedFromTheBirthDateWhenAbsent(): void
    {
        Carbon::setTestNow('2026-10-10');

        $this->assertSame('33', $this->map($this->underwriting())['edad']);
        $this->assertSame('32', $this->map($this->underwriting(['birth_date' => '1993-10-11']))['edad']);
    }

    public function testAnExplicitAgeWinsOverTheDerivedOne(): void
    {
        $this->assertSame('40', $this->map($this->underwriting(['age' => 40]))['edad']);
    }

    public function testBirthDateIsNeverInventedFromAnAge(): void
    {
        $underwriting = $this->underwriting(['age' => 30]);
        unset($underwriting['birth_date']);

        $payload = $this->map($underwriting);

        $this->assertArrayNotHasKey('fecha_nacimiento', $payload);
        $this->assertSame('30', $payload['edad']);
    }

    public function testEveryCanonicalUsageMapsToADistinctHumanoCodeTheyAccept(): void
    {
        $codes = [];

        foreach (VehicleUsageEnum::cases() as $usage) {
            $codes[] = $this->map($this->underwriting(['usage' => $usage->value]))['uso'];
        }

        sort($codes, SORT_NUMERIC);

        $this->assertSame(array_map('strval', array_keys(DatoEnum::USO->allowedValues())), $codes);
        $this->assertSame('4', $this->map($this->underwriting(['usage' => 'private']))['uso']);
        $this->assertSame('6', $this->map($this->underwriting(['usage' => 'taxi']))['uso']);
        $this->assertSame('17', $this->map($this->underwriting(['usage' => 'driving_school']))['uso']);
    }

    public function testMaritalStatusSexAndZoneMapToTheirCodes(): void
    {
        $married = $this->map($this->underwriting([
            'marital_status' => 'married',
            'sex' => 'female',
            'circulation_zone' => 'south',
        ]));
        $unspecified = $this->map($this->underwriting(['sex' => 'unspecified', 'circulation_zone' => 'north']));
        $east = $this->map($this->underwriting(['circulation_zone' => 'east']));

        $this->assertSame('C', $married['estado_civil']);
        $this->assertSame('F', $married['sexo']);
        $this->assertSame('4', $married['zona_circulacion']);
        $this->assertSame('S', $unspecified['estado_civil']);
        $this->assertSame('N', $unspecified['sexo']);
        $this->assertSame('2', $unspecified['zona_circulacion']);
        $this->assertSame('3', $east['zona_circulacion']);
        $this->assertSame('1', $this->map($this->underwriting())['zona_circulacion']);
    }

    public function testCanonicalValuesAreMatchedCaseInsensitively(): void
    {
        $this->assertSame('M', $this->map($this->underwriting(['sex' => ' MALE ']))['sexo']);
    }

    public function testAnUnsupportedValueIsRejectedWithTheCanonicalOnesListed(): void
    {
        $cases = [
            ['usage', 'bus', 'private'],
            ['marital_status', 'widowed', 'single, married'],
            ['sex', 'x', 'male, female, unspecified'],
            ['circulation_zone', 'moon', 'santo_domingo, north, east, south'],
        ];

        foreach ($cases as [$field, $value, $supported]) {
            try {
                $this->map($this->underwriting([$field => $value]));
                $this->fail('Expected a ValidationException for ' . $field);
            } catch (ValidationException $e) {
                $this->assertStringContainsString($field . ' "' . $value . '"', $e->getMessage());
                $this->assertStringContainsString($supported, $e->getMessage());
            }
        }
    }

    public function testRcExcesoAndSumInsuredFallBackToTheConfiguredDefaults(): void
    {
        $car = $this->map($this->underwriting());
        $moto = $this->map($this->underwriting(), PlanEnum::MI_MOTO_BASICO);

        $this->assertSame('1', $car['rc_exceso']);
        $this->assertSame('5000000', $car['suma_asegurada_auto_exceso']);
        $this->assertSame('3000000', $moto['suma_asegurada_auto_exceso']);
    }

    public function testTheDefaultsCanBeOverriddenFromConfig(): void
    {
        config([
            'insurance.humano.default_rc_exceso' => '2',
            'insurance.humano.default_suma_asegurada_auto_exceso' => '10000000',
            'insurance.humano.default_suma_asegurada_moto_exceso' => '1000000',
        ]);

        $car = $this->map($this->underwriting());
        $moto = $this->map($this->underwriting(), PlanEnum::MI_MOTO_BASICO);

        $this->assertSame('2', $car['rc_exceso']);
        $this->assertSame('10000000', $car['suma_asegurada_auto_exceso']);
        $this->assertSame('1000000', $moto['suma_asegurada_auto_exceso']);
    }

    public function testVehicleCodesYearAndRoundedValueComeFromTheReferenceAndProfile(): void
    {
        $payload = $this->map($this->underwriting());

        $this->assertSame('40', $payload['marca']);
        $this->assertSame('2', $payload['modelo']);
        $this->assertSame('2', $payload['version']);
        $this->assertSame(2022, $payload['anio']);
        $this->assertSame(850000, $payload['valor_vehiculo']);
    }

    public function testAnEmptyConfigValueFallsBackToTheDocumentedDefault(): void
    {
        config([
            'insurance.humano.default_rc_exceso' => '',
            'insurance.humano.default_suma_asegurada_auto_exceso' => '  ',
            'insurance.humano.default_suma_asegurada_moto_exceso' => null,
        ]);

        $car = $this->map($this->underwriting());
        $moto = $this->map($this->underwriting(), PlanEnum::MI_MOTO_BASICO);

        $this->assertSame('1', $car['rc_exceso']);
        $this->assertSame('5000000', $car['suma_asegurada_auto_exceso']);
        $this->assertSame('3000000', $moto['suma_asegurada_auto_exceso']);
    }

    public function testMissingFieldsAreReportedWithoutAnyHumanoCode(): void
    {
        $mapper = new CanonicalQuotePayloadMapper();
        $partial = $this->input(['ip_address' => '10.0.0.1', 'usage' => 'private']);

        $this->assertSame([], $mapper->missingFields($this->input($this->underwriting()), PlanEnum::MI_AUTO_FULL));
        $this->assertSame(
            ['fecha_nacimiento', 'edad', 'estado_civil', 'sexo', 'zona_circulacion'],
            $mapper->missingFields($partial, PlanEnum::MI_AUTO_FULL)
        );
    }

    public function testACompleteCanonicalInputSatisfiesTheQuoteRequest(): void
    {
        $request = QuoteRequest::make(PlanEnum::MI_AUTO_FULL, $this->map($this->underwriting()));

        $this->assertCount(14, $request->datos);
    }

    public function testAnIncompleteInputLeavesGapsForTheQuoteRequestToName(): void
    {
        $underwriting = $this->underwriting();
        unset($underwriting['usage'], $underwriting['birth_date']);

        try {
            QuoteRequest::make(PlanEnum::MI_AUTO_FULL, $this->map($underwriting));
            $this->fail('Expected a ValidationException');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('uso', $e->getMessage());
            $this->assertStringContainsString('fecha_nacimiento', $e->getMessage());
            $this->assertStringNotContainsString('sexo', $e->getMessage());
        }
    }
}
