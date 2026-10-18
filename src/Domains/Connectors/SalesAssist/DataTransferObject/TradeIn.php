<?php

declare(strict_types=1);

namespace Kanvas\Connectors\SalesAssist\DataTransferObject;

use Baka\Support\Arr;
use Baka\Support\Str;
use Spatie\LaravelData\Data;

/**
 * A submitted trade-in form, read the same way for every dealer CRM (Elead, VinSolution, ...)
 * and the trade-in PDF; each connector maps it onto its own API payload.
 */
class TradeIn extends Data
{
    /**
     * Slugged keys/labels each field arrives under — the extension renames its labels freely.
     */
    protected const array FIELD_ALIASES = [
        'vin' => ['vin', 'vehicle_identification_number_vin', 'vehicle_identification_number'],
        'year' => ['year', 'ano'],
        'make' => ['make'],
        'model' => ['model'],
        'trim' => ['trim'],
        'mileage' => ['mileage', 'odometer', 'estimated_mileage'],
        'interior_color' => ['int_color', 'interior_color'],
        'exterior_color' => ['ext_color', 'exterior_color'],
        'transmission' => ['transmission', 'trans'],
        'body_style' => ['body_style', 'body_type'],
        'engine' => ['engine'],
        'drive_train' => ['drive_train', 'drivetrain'],
        'doors' => ['doors'],
        'value' => ['value'],
        'payoff' => ['payoff_amount', 'payoff'],
    ];

    public function __construct(
        public readonly int $year,
        public readonly string $make,
        public readonly string $model,
        public readonly string $trim,
        public readonly string $vin,
        public readonly int $mileage,
        public readonly string $interiorColor,
        public readonly string $exteriorColor,
        public readonly string $transmission = '',
        public readonly string $bodyStyle = '',
        public readonly string $engine = '',
        public readonly string $driveTrain = '',
        public readonly string $doors = '',
        public readonly float $value = 0.0,
        public readonly float $payoff = 0.0,
        /** @var array<string, int[]> filesystem ids per upload field, e.g. upload_vehicle_photo => [1, 2] */
        public readonly array $photoIds = [],
    ) {
    }

    /**
     * Web forms send `data.form` keyed by field; the browser extension sends `data` as a
     * `[{label, value}]` list.
     */
    public static function fromMessageData(array $data): self
    {
        $form = [];
        foreach ($data['form'] ?? Arr::fromLabelValuePairs($data) as $key => $value) {
            $form[Str::slug((string) $key, '_')] = $value;
        }

        $field = function (string $name) use ($form): string {
            foreach (self::FIELD_ALIASES[$name] as $alias) {
                $value = is_scalar($form[$alias] ?? null) ? Str::trimToNull((string) $form[$alias]) : null;

                if ($value !== null) {
                    return $value;
                }
            }

            return '';
        };
        $number = fn (string $name): string => str_replace([',', '$'], '', $field($name));

        return new self(
            year: (int) $field('year'),
            make: $field('make'),
            model: $field('model'),
            trim: substr($field('trim'), 0, 50),
            vin: strtoupper($field('vin')),
            mileage: (int) $number('mileage'),
            interiorColor: $field('interior_color'),
            exteriorColor: $field('exterior_color'),
            transmission: $field('transmission'),
            bodyStyle: $field('body_style'),
            engine: $field('engine'),
            driveTrain: $field('drive_train'),
            doors: $field('doors'),
            value: round(max(0.0, (float) $number('value')), 2),
            payoff: round(max(0.0, (float) $number('payoff')), 2),
            photoIds: self::photoIdsFrom($form),
        );
    }

    /**
     * Upload fields carry comma-separated filesystem ids ("11,12,13").
     */
    protected static function photoIdsFrom(array $form): array
    {
        $photoIds = [];
        foreach ($form as $key => $value) {
            if (! str_contains((string) $key, 'photo') || ! is_scalar($value)) {
                continue;
            }

            $ids = array_values(array_filter(array_map('intval', explode(',', (string) $value))));
            if ($ids !== []) {
                $photoIds[$key] = $ids;
            }
        }

        return $photoIds;
    }

    /**
     * CRMs reject a trade-in without a VIN or a year/make/model (eLead: InsufficientTradeInDetails).
     */
    public function hasEnoughDetails(): bool
    {
        return $this->vin !== '' || ($this->year > 0 && $this->make !== '' && $this->model !== '');
    }
}
