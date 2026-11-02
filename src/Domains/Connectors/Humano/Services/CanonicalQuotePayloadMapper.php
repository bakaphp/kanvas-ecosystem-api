<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Humano\Services;

use BackedEnum;
use Baka\Support\Str;
use Illuminate\Support\Carbon;
use Kanvas\Connectors\Humano\Enums\DatoEnum;
use Kanvas\Connectors\Humano\Enums\PlanEnum;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Insurance\DataTransferObject\CanonicalQuoteInput;
use Kanvas\Insurance\DataTransferObject\ExternalVehicleReference;
use Kanvas\Insurance\Enums\CirculationZoneEnum;
use Kanvas\Insurance\Enums\InsuranceUnderwritingFieldEnum;
use Kanvas\Insurance\Enums\MaritalStatusEnum;
use Kanvas\Insurance\Enums\SexEnum;
use Kanvas\Insurance\Enums\VehicleUsageEnum;

class CanonicalQuotePayloadMapper
{
    private const BIRTH_DATE_FORMAT = 'd-m-Y';

    private const DEFAULT_RC_EXCESO = '1';

    private const DEFAULT_SUMA_AUTO_EXCESO = '5000000';

    private const DEFAULT_SUMA_MOTO_EXCESO = '3000000';

    public function payload(
        CanonicalQuoteInput $input,
        PlanEnum $plan,
        ExternalVehicleReference $reference
    ): array {
        return array_filter([
            'marca' => $reference->brandId,
            'modelo' => $reference->modelId,
            'version' => $reference->versionId,
            'anio' => $input->vehicle->year,
            'valor_vehiculo' => $input->profile->estimatedValue !== null
                ? (int) round($input->profile->estimatedValue)
                : null,
            'direccion_ip' => $input->underwritingValue(InsuranceUnderwritingFieldEnum::IP_ADDRESS),
            'fecha_nacimiento' => $this->birthDate($input),
            'edad' => $this->age($input),
            'estado_civil' => $this->maritalStatus($input),
            'sexo' => $this->sex($input),
            'uso' => $this->usage($input),
            'zona_circulacion' => $this->circulationZone($input),
            'rc_exceso' => $this->rcExceso(),
            'suma_asegurada_auto_exceso' => $this->sumInsuredExcess($plan),
        ], fn (mixed $value): bool => $value !== null);
    }

    public function missingFields(CanonicalQuoteInput $input, PlanEnum $plan): array
    {
        $payload = $this->payload(
            $input,
            $plan,
            new ExternalVehicleReference(brandId: '0', modelId: '0', versionId: '0')
        );

        return array_values(array_diff($this->requiredKeys(), array_keys($payload)));
    }

    public function rcExceso(): string
    {
        return Str::trimToNull((string) config('insurance.humano.default_rc_exceso')) ?? self::DEFAULT_RC_EXCESO;
    }

    public function sumInsuredExcess(PlanEnum $plan): string
    {
        if ($plan === PlanEnum::MI_MOTO_BASICO) {
            return Str::trimToNull((string) config('insurance.humano.default_suma_asegurada_moto_exceso'))
                ?? self::DEFAULT_SUMA_MOTO_EXCESO;
        }

        return Str::trimToNull((string) config('insurance.humano.default_suma_asegurada_auto_exceso'))
            ?? self::DEFAULT_SUMA_AUTO_EXCESO;
    }

    private function requiredKeys(): array
    {
        return array_merge(
            ['direccion_ip'],
            array_map(fn (DatoEnum $dato): string => $dato->payloadKey(), DatoEnum::fromPayload())
        );
    }

    private function birthDate(CanonicalQuoteInput $input): ?string
    {
        return $input->birthDate()?->format(self::BIRTH_DATE_FORMAT);
    }

    private function age(CanonicalQuoteInput $input): ?string
    {
        $age = $input->underwritingValue(InsuranceUnderwritingFieldEnum::AGE);

        if ($age !== null) {
            return $age;
        }

        $birthDate = $input->birthDate();

        return $birthDate === null ? null : (string) Carbon::instance($birthDate)->age;
    }

    private function maritalStatus(CanonicalQuoteInput $input): ?string
    {
        $status = $this->canonicalEnum(
            $input,
            InsuranceUnderwritingFieldEnum::MARITAL_STATUS,
            MaritalStatusEnum::class
        );

        return match ($status) {
            null => null,
            MaritalStatusEnum::SINGLE => 'S',
            MaritalStatusEnum::MARRIED => 'C',
        };
    }

    private function sex(CanonicalQuoteInput $input): ?string
    {
        $sex = $this->canonicalEnum($input, InsuranceUnderwritingFieldEnum::SEX, SexEnum::class);

        return match ($sex) {
            null => null,
            SexEnum::MALE => 'M',
            SexEnum::FEMALE => 'F',
            SexEnum::UNSPECIFIED => 'N',
        };
    }

    private function circulationZone(CanonicalQuoteInput $input): ?string
    {
        $zone = $this->canonicalEnum(
            $input,
            InsuranceUnderwritingFieldEnum::CIRCULATION_ZONE,
            CirculationZoneEnum::class
        );

        return match ($zone) {
            null => null,
            CirculationZoneEnum::SANTO_DOMINGO => '1',
            CirculationZoneEnum::NORTH => '2',
            CirculationZoneEnum::EAST => '3',
            CirculationZoneEnum::SOUTH => '4',
        };
    }

    private function usage(CanonicalQuoteInput $input): ?string
    {
        $usage = $this->canonicalEnum($input, InsuranceUnderwritingFieldEnum::USAGE, VehicleUsageEnum::class);

        return match ($usage) {
            null => null,
            VehicleUsageEnum::AMBULANCE => '1',
            VehicleUsageEnum::RENTAL => '2',
            VehicleUsageEnum::EXHIBITION_PLATE => '3',
            VehicleUsageEnum::PRIVATE => '4',
            VehicleUsageEnum::PUBLIC => '5',
            VehicleUsageEnum::TAXI => '6',
            VehicleUsageEnum::STAFF_TRANSPORT => '7',
            VehicleUsageEnum::SCHOOL_TRANSPORT => '8',
            VehicleUsageEnum::FUNERAL => '9',
            VehicleUsageEnum::VALUABLES_TRANSPORT => '10',
            VehicleUsageEnum::TOURIST_TRANSPORT => '11',
            VehicleUsageEnum::CONTRACTOR => '12',
            VehicleUsageEnum::FUEL_TRANSPORT => '13',
            VehicleUsageEnum::FLAMMABLE_CARGO => '14',
            VehicleUsageEnum::NON_FLAMMABLE_CARGO => '15',
            VehicleUsageEnum::GARBAGE_COLLECTION => '16',
            VehicleUsageEnum::DRIVING_SCHOOL => '17',
        };
    }

    private function canonicalEnum(
        CanonicalQuoteInput $input,
        InsuranceUnderwritingFieldEnum $field,
        string $enum
    ): ?BackedEnum {
        $value = $input->underwritingValue($field);

        if ($value === null) {
            return null;
        }

        return $enum::tryFrom(strtolower(trim($value)))
            ?? throw new ValidationException(sprintf(
                'Humano: %s "%s" is not supported. Supported: %s',
                $field->value,
                $value,
                implode(', ', array_column($enum::cases(), 'value'))
            ));
    }
}
