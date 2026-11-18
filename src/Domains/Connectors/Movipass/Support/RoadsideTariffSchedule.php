<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Movipass\Support;

use Illuminate\Support\Carbon;
use Kanvas\Connectors\Movipass\Enums\RoadsideServiceTypeEnum;
use Kanvas\Connectors\Movipass\Enums\RoadsideSurchargeReasonEnum;
use Kanvas\Connectors\Movipass\Enums\RoadsideZoneEnum;
use Kanvas\Exceptions\ValidationException;

/**
 * The Asistencia Regional tariff sheet. The defaults are the launch values agreed for El Salvador;
 * the admin overrides any top-level key through the company or app setting (see
 * RegionalAssistance), and `services` per service, so a price change never needs a deploy.
 */
final class RoadsideTariffSchedule
{
    private const DEFAULTS = [
        'timezone' => 'America/El_Salvador',
        'km_zero' => ['lat' => 13.6977, 'lng' => -89.1910],
        'included_km' => 10,
        'surcharge_rate' => 0.20,
        'tax_rate' => 0.13,
        // End is exclusive, so 08:30 sharp is already off-peak.
        'surcharge_windows' => [
            ['reason' => 'peak_hour', 'start' => '06:00', 'end' => '08:30'],
            ['reason' => 'peak_hour', 'start' => '11:30', 'end' => '13:30'],
            ['reason' => 'peak_hour', 'start' => '16:00', 'end' => '19:00'],
            ['reason' => 'late_night', 'start' => '01:00', 'end' => '05:00'],
        ],
        // MM-DD repeats every year; Semana Santa moves, so it is configured as YYYY-MM-DD dates.
        'holidays' => ['01-01', '05-01', '05-10', '06-17', '08-06', '09-15', '11-02', '12-25'],
        'services' => [
            'jump_start' => ['amss' => 15.00, 'other_city' => 20.00, 'foraneo' => 35.00, 'per_km' => null],
            'tire_change' => ['amss' => 15.00, 'other_city' => 20.00, 'foraneo' => 35.00, 'per_km' => null],
            'fuel_delivery' => ['amss' => 15.00, 'other_city' => 20.00, 'foraneo' => 35.00, 'per_km' => null],
            'light_tow' => ['amss' => 45.00, 'other_city' => 50.00, 'foraneo' => 50.00, 'per_km' => 2.00],
            'accident_tow' => ['amss' => 45.00, 'other_city' => 50.00, 'foraneo' => 50.00, 'per_km' => 2.00],
            'motorcycle_tow' => ['amss' => 30.00, 'other_city' => 40.00, 'foraneo' => 40.00, 'per_km' => 2.00],
            'locksmith' => ['amss' => 25.00, 'other_city' => 35.00, 'foraneo' => 35.00, 'per_km' => 1.50],
            'designated_driver' => ['amss' => 25.00, 'other_city' => 40.00, 'foraneo' => 45.00, 'per_km' => 2.00],
        ],
        // [{zone: amss|other_city|foraneo, polygon: [{lat, lng}, ...]}]; first match wins.
        'zones' => [],
    ];

    private function __construct(private readonly array $config)
    {
    }

    public static function fromArray(array $overrides): self
    {
        return new self([
            ...self::DEFAULTS,
            ...$overrides,
            'services' => array_replace(self::DEFAULTS['services'], $overrides['services'] ?? []),
        ]);
    }

    public function timezone(): string
    {
        return (string) $this->config['timezone'];
    }

    /**
     * @return array{lat: float, lng: float}
     */
    public function kmZero(): array
    {
        return [
            'lat' => (float) $this->config['km_zero']['lat'],
            'lng' => (float) $this->config['km_zero']['lng'],
        ];
    }

    public function includedKm(): float
    {
        return (float) $this->config['included_km'];
    }

    public function surchargeRate(): float
    {
        return (float) $this->config['surcharge_rate'];
    }

    public function taxRate(): float
    {
        return (float) $this->config['tax_rate'];
    }

    public function baseFare(RoadsideServiceTypeEnum $service, RoadsideZoneEnum $zone): float
    {
        $fare = $this->serviceRates($service)[$zone->value] ?? null;

        if (! is_numeric($fare)) {
            throw new ValidationException(sprintf(
                'No %s fare configured for the %s zone',
                $service->label(),
                $zone->value,
            ));
        }

        return (float) $fare;
    }

    public function perKmRate(RoadsideServiceTypeEnum $service): ?float
    {
        $rate = $this->serviceRates($service)['per_km'] ?? null;

        return is_numeric($rate) ? (float) $rate : null;
    }

    /**
     * Launch coverage is AMSS only, so with no geofence loaded yet every point prices as AMSS.
     * Once any geofence exists, a point outside all of them is foráneo.
     */
    public function zoneFor(float $lat, float $lng): RoadsideZoneEnum
    {
        $zones = $this->config['zones'];

        if ($zones === []) {
            return RoadsideZoneEnum::AMSS;
        }

        foreach ($zones as $zone) {
            if (Geo::polygonContains($zone['polygon'] ?? [], $lat, $lng)) {
                return RoadsideZoneEnum::from((string) $zone['zone']);
            }
        }

        return RoadsideZoneEnum::FORANEO;
    }

    /**
     * Every condition the moment falls in. The caller applies a single surcharge however many
     * match, but keeps the list so the receipt can say why.
     *
     * @return array<int, RoadsideSurchargeReasonEnum>
     */
    public function surchargeReasonsAt(Carbon $moment): array
    {
        $local = $moment->copy()->setTimezone($this->timezone());
        $minuteOfDay = $local->hour * 60 + $local->minute;
        $reasons = [];

        if ($this->isHoliday($local)) {
            $reasons[RoadsideSurchargeReasonEnum::HOLIDAY->value] = RoadsideSurchargeReasonEnum::HOLIDAY;
        }

        foreach ($this->config['surcharge_windows'] as $window) {
            $start = $this->minuteOfDay((string) $window['start']);
            $end = $this->minuteOfDay((string) $window['end']);

            if ($minuteOfDay >= $start && $minuteOfDay < $end) {
                $reason = RoadsideSurchargeReasonEnum::from((string) $window['reason']);
                $reasons[$reason->value] = $reason;
            }
        }

        return array_values($reasons);
    }

    private function isHoliday(Carbon $local): bool
    {
        $holidays = $this->config['holidays'];

        return in_array($local->format('m-d'), $holidays, true)
            || in_array($local->format('Y-m-d'), $holidays, true);
    }

    private function minuteOfDay(string $time): int
    {
        [$hour, $minute] = array_map('intval', explode(':', $time));

        return $hour * 60 + $minute;
    }

    private function serviceRates(RoadsideServiceTypeEnum $service): array
    {
        $rates = $this->config['services'][$service->value] ?? null;

        if (! is_array($rates)) {
            throw new ValidationException(sprintf('No tariff configured for %s', $service->label()));
        }

        return $rates;
    }
}
