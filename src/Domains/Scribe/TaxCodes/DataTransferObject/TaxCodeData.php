<?php

declare(strict_types=1);

namespace Kanvas\Scribe\TaxCodes\DataTransferObject;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\DataCollection;

/**
 * @property DataCollection<TaxRateData>|null $rates
 */
class TaxCodeData extends Data
{
    public function __construct(
        public readonly AppInterface $app,
        public readonly CompanyInterface $company,
        public readonly string $code,
        public readonly string $name,
        public readonly ?string $jurisdiction = null,
        public readonly bool $is_active = true,
        public readonly string $source = 'kanvas',
        public readonly ?string $external_id = null,
        public readonly ?array $metadata = null,
        /** @var DataCollection<TaxRateData>|null */
        public readonly ?DataCollection $rates = null,
    ) {
    }
}
