<?php

declare(strict_types=1);

namespace Kanvas\Souk\Taxes\DataTransferObject;

use Kanvas\Apps\Models\Apps;
use Spatie\LaravelData\Data;

class TaxAddress extends Data
{
    public function __construct(
        public readonly ?string $country,
        public readonly ?string $state,
        public readonly ?string $city,
        public readonly ?string $postalCode,
        public readonly ?string $line1 = null,
    ) {
    }

    public static function shipFromConfig(Apps $app): ?self
    {
        $raw = $app->get('souk_tax_ship_from');

        if (! is_array($raw) || empty($raw['country'])) {
            return null;
        }

        return new self(
            country: (string) $raw['country'],
            state: isset($raw['state']) ? (string) $raw['state'] : null,
            city: isset($raw['city']) ? (string) $raw['city'] : null,
            postalCode: isset($raw['postal_code']) ? (string) $raw['postal_code'] : null,
            line1: isset($raw['line1']) ? (string) $raw['line1'] : null,
        );
    }

    public function isSufficientFor(string $country): bool
    {
        return match (strtoupper($country)) {
            'DO' => $this->country !== null,
            'US' => $this->country !== null && $this->state !== null && $this->postalCode !== null,
            'MX' => $this->country !== null && $this->postalCode !== null,
            default => $this->country !== null,
        };
    }
}
