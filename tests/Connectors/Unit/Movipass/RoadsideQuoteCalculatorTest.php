<?php

declare(strict_types=1);

namespace Tests\Connectors\Unit\Movipass;

use Illuminate\Support\Carbon;
use Kanvas\Connectors\Movipass\Enums\RoadsideServiceTypeEnum;
use Kanvas\Connectors\Movipass\Enums\RoadsideSurchargeReasonEnum;
use Kanvas\Connectors\Movipass\Enums\RoadsideZoneEnum;
use Kanvas\Connectors\Movipass\Services\RoadsideQuoteCalculator;
use Kanvas\Connectors\Movipass\Support\RoadsideTariffSchedule;
use Kanvas\Exceptions\ValidationException;
use Tests\TestCase;

final class RoadsideQuoteCalculatorTest extends TestCase
{
    private const KM_ZERO = ['lat' => 13.6977, 'lng' => -89.1910];

    // ~11.5 km north of Kilómetro Cero: 1.5 km past the included 10, billed as 2 started km.
    private const ELEVEN_AND_A_HALF_KM_AWAY = ['lat' => 13.8012, 'lng' => -89.1910];

    public function testTheSpecWorkedExampleTotals1898(): void
    {
        $quote = $this->calculator(['services' => [
            'light_tow' => ['amss' => 10.00, 'other_city' => 10.00, 'foraneo' => 10.00, 'per_km' => 2.00],
        ]])->quote(
            service: RoadsideServiceTypeEnum::LIGHT_TOW,
            location: self::KM_ZERO,
            destination: self::ELEVEN_AND_A_HALF_KM_AWAY,
            moment: $this->localTime('2026-10-20 07:00'),
        );

        $this->assertSame(2, $quote->billableKm);
        $this->assertSame(4.00, $quote->extraKmAmount);
        $this->assertSame(14.00, $quote->price->subtotal);
        $this->assertSame(2.80, $quote->price->surchargeAmount);
        $this->assertSame(16.80, $quote->price->taxableBase());
        $this->assertSame(2.18, $quote->price->taxAmount);
        $this->assertSame(18.98, $quote->price->total);
    }

    public function testOffPeakHasNoSurcharge(): void
    {
        $quote = $this->calculator()->quote(
            service: RoadsideServiceTypeEnum::JUMP_START,
            location: self::KM_ZERO,
            destination: null,
            moment: $this->localTime('2026-10-20 10:00'),
        );

        $this->assertSame([], $quote->surchargeReasons);
        $this->assertSame(0.0, $quote->price->surchargeAmount);
        $this->assertSame(15.00, $quote->price->subtotal);
        $this->assertSame(16.95, $quote->price->total);
    }

    public function testHolidayDuringPeakHourChargesASingleSurcharge(): void
    {
        $quote = $this->calculator()->quote(
            service: RoadsideServiceTypeEnum::JUMP_START,
            location: self::KM_ZERO,
            destination: null,
            moment: $this->localTime('2026-09-15 17:00'),
        );

        $this->assertEqualsCanonicalizing(
            [RoadsideSurchargeReasonEnum::HOLIDAY->value, RoadsideSurchargeReasonEnum::PEAK_HOUR->value],
            array_column($quote->surchargeReasons, 'value'),
        );
        $this->assertSame(3.00, $quote->price->surchargeAmount);
    }

    public function testSurchargeWindowsIncludeTheirStartAndExcludeTheirEnd(): void
    {
        $schedule = RoadsideTariffSchedule::fromArray([]);

        $this->assertSame(
            [RoadsideSurchargeReasonEnum::LATE_NIGHT],
            $schedule->surchargeReasonsAt($this->localTime('2026-10-20 01:00')),
        );
        $this->assertSame([], $schedule->surchargeReasonsAt($this->localTime('2026-10-20 05:00')));
        $this->assertSame([], $schedule->surchargeReasonsAt($this->localTime('2026-10-20 08:30')));
    }

    public function testOnSiteServicesBillTheUserLocationAndIgnoreTheDestination(): void
    {
        $quote = $this->calculator()->quote(
            service: RoadsideServiceTypeEnum::LOCKSMITH,
            location: self::ELEVEN_AND_A_HALF_KM_AWAY,
            destination: self::KM_ZERO,
            moment: $this->localTime('2026-10-20 10:00'),
        );

        $this->assertSame(2, $quote->billableKm);
        $this->assertSame(3.00, $quote->extraKmAmount);
    }

    public function testServicesWithoutAPerKmRateNeverChargeDistance(): void
    {
        $quote = $this->calculator()->quote(
            service: RoadsideServiceTypeEnum::FUEL_DELIVERY,
            location: self::ELEVEN_AND_A_HALF_KM_AWAY,
            destination: null,
            moment: $this->localTime('2026-10-20 10:00'),
        );

        $this->assertSame(2, $quote->billableKm);
        $this->assertSame(0.0, $quote->extraKmAmount);
    }

    public function testATowWithoutDestinationCannotBeQuoted(): void
    {
        $this->expectException(ValidationException::class);

        $this->calculator()->quote(
            service: RoadsideServiceTypeEnum::LIGHT_TOW,
            location: self::KM_ZERO,
            destination: null,
            moment: $this->localTime('2026-10-20 10:00'),
        );
    }

    public function testZoneComesFromTheGeofenceAndFallsBackToForaneo(): void
    {
        $calculator = $this->calculator(['zones' => [[
            'zone' => 'amss',
            'polygon' => [
                ['lat' => 13.60, 'lng' => -89.30],
                ['lat' => 13.60, 'lng' => -89.10],
                ['lat' => 13.75, 'lng' => -89.10],
                ['lat' => 13.75, 'lng' => -89.30],
            ],
        ]]]);

        $inside = $calculator->quote(
            service: RoadsideServiceTypeEnum::JUMP_START,
            location: self::KM_ZERO,
            destination: null,
            moment: $this->localTime('2026-10-20 10:00'),
        );
        $outside = $calculator->quote(
            service: RoadsideServiceTypeEnum::JUMP_START,
            location: self::ELEVEN_AND_A_HALF_KM_AWAY,
            destination: null,
            moment: $this->localTime('2026-10-20 10:00'),
        );

        $this->assertSame(RoadsideZoneEnum::AMSS, $inside->zone);
        $this->assertSame(15.00, $inside->baseFare);
        $this->assertSame(RoadsideZoneEnum::FORANEO, $outside->zone);
        $this->assertSame(35.00, $outside->baseFare);
    }

    public function testAdditionalChargeIsSurchargedWhenTheOrderWasPlacedAtPeakHour(): void
    {
        $price = $this->calculator()->priceAdditionalCharge(10.00, $this->localTime('2026-10-20 07:00'));

        $this->assertSame(2.00, $price->surchargeAmount);
        $this->assertSame(1.56, $price->taxAmount);
        $this->assertSame(13.56, $price->total);
    }

    public function testAdditionalChargeOnAnOffPeakOrderIsOnlyTaxed(): void
    {
        $price = $this->calculator()->priceAdditionalCharge(10.00, $this->localTime('2026-10-20 10:00'));

        $this->assertSame(0.0, $price->surchargeAmount);
        $this->assertSame(11.30, $price->total);
    }

    private function calculator(array $overrides = []): RoadsideQuoteCalculator
    {
        return new RoadsideQuoteCalculator(RoadsideTariffSchedule::fromArray($overrides));
    }

    private function localTime(string $time): Carbon
    {
        return Carbon::parse($time, 'America/El_Salvador');
    }
}
