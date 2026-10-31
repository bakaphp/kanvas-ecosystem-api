<?php

declare(strict_types=1);

namespace Kanvas\Souk\Taxes\Contracts;

use Kanvas\Souk\Taxes\DataTransferObject\TaxContext;
use Kanvas\Souk\Taxes\DataTransferObject\TaxCoverage;
use Kanvas\Souk\Taxes\DataTransferObject\TaxResult;
use Kanvas\Souk\Taxes\DataTransferObject\TaxReversal;
use Kanvas\Souk\Taxes\Models\OrderTaxSnapshot;

interface TaxProviderInterface
{
    public function calculate(TaxContext $context): TaxResult;

    public function commit(OrderTaxSnapshot $snapshot): void;

    public function reverse(OrderTaxSnapshot $snapshot, TaxReversal $reversal): void;

    public function void(OrderTaxSnapshot $snapshot): void;

    public function coverage(string $country, ?string $state = null): TaxCoverage;
}
