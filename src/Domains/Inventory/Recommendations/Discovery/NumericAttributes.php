<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Recommendations\Discovery;

use Baka\Contracts\AppInterface;
use Baka\Support\Number;
use Kanvas\Inventory\Recommendations\Enums\ConfigurationEnum;

/**
 * The tenant's filterable numeric attributes, from the
 * `product_discovery_numeric_attributes` app setting:
 * `{"mileage": {"attribute": "Mileage", "unit": "mi"}, "year": {"attribute": "Year"}}`.
 *
 * The unit is the field's canonical unit. Index values and incoming filters are
 * both converted to it, so "100000 km" and "100000 miles" never share a threshold.
 */
final class NumericAttributes
{
    private const array UNIT_ALIASES = [
        'mi' => 'mi',
        'mile' => 'mi',
        'miles' => 'mi',
        'milla' => 'mi',
        'millas' => 'mi',
        'km' => 'km',
        'kms' => 'km',
        'kilometer' => 'km',
        'kilometers' => 'km',
        'kilometro' => 'km',
        'kilometros' => 'km',
    ];

    /** Length units, as kilometres per unit. */
    private const array KM_PER_UNIT = [
        'mi' => 1.609344,
        'km' => 1.0,
    ];

    /**
     * @param array<string, array{attribute: string, unit: string|null}> $fields
     */
    private function __construct(
        private readonly array $fields,
    ) {
    }

    public static function fromApp(AppInterface $app): self
    {
        $fields = [];

        foreach (ConfigurationEnum::NUMERIC_ATTRIBUTES->listFrom($app) as $key => $config) {
            if (! is_string($key) || preg_match('/^[a-z0-9_]+$/', $key) !== 1) {
                continue;
            }

            $attribute = is_array($config) ? ($config['attribute'] ?? null) : $config;

            if (! is_string($attribute) || trim($attribute) === '') {
                continue;
            }

            $unit = is_array($config) && is_string($config['unit'] ?? null) ? self::canonicalUnit($config['unit']) : null;

            $fields[$key] = [
                'attribute' => trim($attribute),
                'unit' => $unit,
            ];
        }

        return new self($fields);
    }

    /**
     * @return list<string>
     */
    public function keys(): array
    {
        return array_keys($this->fields);
    }

    public function has(string $key): bool
    {
        return isset($this->fields[$key]);
    }

    public function attributeFor(string $key): ?string
    {
        return $this->fields[$key]['attribute'] ?? null;
    }

    public function unitFor(string $key): ?string
    {
        return $this->fields[$key]['unit'] ?? null;
    }

    /**
     * What the agent is told it can filter on, e.g. ["mileage (mi)", "year"].
     *
     * @return list<string>
     */
    public function describe(): array
    {
        $described = [];

        foreach ($this->fields as $key => $field) {
            $described[] = $field['unit'] !== null ? $key . ' (' . $field['unit'] . ')' : $key;
        }

        return $described;
    }

    /** The `numeric_filters` parameter description both agent runtimes show the model. */
    public function agentGuidance(): string
    {
        $fields = $this->describe();

        return $fields === []
            ? 'Numeric limits the shopper stated. This catalog has no numeric filters configured; leave empty.'
            : 'Numeric limits the shopper stated. Filterable fields: ' . implode(', ', $fields)
                . '. "Around 60, 70, 80 thousand miles, under 100k" → field "mileage", max 100000, unit "mi".';
    }

    public static function minField(string $key): string
    {
        return 'num_' . $key . '_min';
    }

    public static function maxField(string $key): string
    {
        return 'num_' . $key . '_max';
    }

    /**
     * A value in `$fromUnit` expressed in the field's canonical unit. Null when the
     * unit is unknown or can't convert ("liters" on mileage); no unit means the
     * value is already canonical.
     */
    public function toCanonical(string $key, float $value, ?string $fromUnit): ?float
    {
        $target = $this->unitFor($key);

        if ($fromUnit === null || trim($fromUnit) === '') {
            return $value;
        }

        $source = self::canonicalUnit($fromUnit);

        if ($target === null) {
            return $source === null ? null : $value;
        }

        if ($source === $target) {
            return $value;
        }

        if ($source === null || ! isset(self::KM_PER_UNIT[$source], self::KM_PER_UNIT[$target])) {
            return null;
        }

        return $value * self::KM_PER_UNIT[$source] / self::KM_PER_UNIT[$target];
    }

    /**
     * "62,000 mi" → 62000.0 in the field's unit. Null for anything that isn't a
     * number ("low miles"), which then indexes as absent rather than as 0.
     */
    public function parseValue(string $key, mixed $raw): ?float
    {
        if (is_int($raw) || is_float($raw)) {
            return (float) $raw;
        }

        if (! is_string($raw)) {
            return null;
        }

        $measurement = Number::parseMeasurement($raw);

        if ($measurement === null) {
            return null;
        }

        $unit = $measurement['unit'] !== null && self::canonicalUnit($measurement['unit']) !== null
            ? $measurement['unit']
            : null;

        return $this->toCanonical($key, $measurement['value'], $unit);
    }

    public static function canonicalUnit(string $unit): ?string
    {
        return self::UNIT_ALIASES[mb_strtolower(trim($unit))] ?? null;
    }
}
