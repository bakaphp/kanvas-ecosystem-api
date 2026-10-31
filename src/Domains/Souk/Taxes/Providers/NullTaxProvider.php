<?php

declare(strict_types=1);

namespace Kanvas\Souk\Taxes\Providers;

use Kanvas\Souk\Taxes\Contracts\TaxProviderInterface;
use Kanvas\Souk\Taxes\DataTransferObject\TaxContext;
use Kanvas\Souk\Taxes\DataTransferObject\TaxCoverage;
use Kanvas\Souk\Taxes\DataTransferObject\TaxResult;
use Kanvas\Souk\Taxes\DataTransferObject\TaxReversal;
use Kanvas\Souk\Taxes\Enums\TaxProviderEnum;
use Kanvas\Souk\Taxes\Models\OrderTaxSnapshot;

class NullTaxProvider implements TaxProviderInterface
{
    public function calculate(TaxContext $context): TaxResult
    {
        return TaxResult::nullResult();
    }

    public function commit(OrderTaxSnapshot $snapshot): void
    {
    }

    public function void(OrderTaxSnapshot $snapshot): void
    {
    }

    public function reverse(OrderTaxSnapshot $snapshot, TaxReversal $reversal): void
    {
    }

    public function coverage(string $country, ?string $state = null): TaxCoverage
    {
        return new TaxCoverage(
            country: $country,
            state: $state,
            provider: TaxProviderEnum::NONE->value,
            isSupported: true,
        );
    }
}
