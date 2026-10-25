<?php

declare(strict_types=1);

namespace Kanvas\Souk\Taxes\Providers;

use Brick\Math\BigDecimal;
use Kanvas\Souk\Support\Money;
use Kanvas\Souk\Taxes\Contracts\TaxProviderInterface;
use Kanvas\Souk\Taxes\DataTransferObject\TaxComponent;
use Kanvas\Souk\Taxes\DataTransferObject\TaxContext;
use Kanvas\Souk\Taxes\DataTransferObject\TaxCoverage;
use Kanvas\Souk\Taxes\DataTransferObject\TaxResult;
use Kanvas\Souk\Taxes\DataTransferObject\TaxReversal;
use Kanvas\Souk\Taxes\DataTransferObject\TaxableLine;
use Kanvas\Souk\Taxes\Enums\TaxProviderEnum;
use Kanvas\Souk\Taxes\Enums\TaxTreatmentEnum;
use Kanvas\Souk\Taxes\Models\CustomerTaxExemption;
use Kanvas\Souk\Taxes\Models\OrderTaxSnapshot;
use Kanvas\Souk\Taxes\Models\TaxRoutingRule;

class ManualRateTaxProvider implements TaxProviderInterface
{
    public function __construct(
        private readonly TaxRoutingRule $rule,
        private readonly ?CustomerTaxExemption $exemption = null,
    ) {
    }

    public function calculate(TaxContext $context): TaxResult
    {
        if ($this->exemption !== null) {
            $components = array_map(
                fn (TaxableLine $line) => new TaxComponent(
                    lineId: $line->lineId,
                    taxType: 'ITBIS',
                    jurisdiction: $this->rule->destination_country,
                    rate: '0',
                    taxableAmount: '0',
                    taxAmount: '0',
                    taxabilityReason: 'customer_exempt',
                ),
                $context->lines,
            );

            return new TaxResult(
                treatment: TaxTreatmentEnum::EXEMPT,
                totalTax: '0',
                totalTaxable: '0',
                components: $components,
            );
        }

        $rate = Money::of((string) $this->rule->rate);
        $components = [];
        $totalTax = BigDecimal::zero();
        $totalTaxable = BigDecimal::zero();

        foreach ($context->lines as $line) {
            if (! $line->isTaxable) {
                $components[] = new TaxComponent(
                    lineId: $line->lineId,
                    taxType: 'ITBIS',
                    jurisdiction: $this->rule->destination_country,
                    rate: '0',
                    taxableAmount: '0',
                    taxAmount: '0',
                    taxabilityReason: 'product_not_taxable',
                );

                continue;
            }

            if ($line->isShipping && ! $this->rule->tax_shipping) {
                $components[] = new TaxComponent(
                    lineId: $line->lineId,
                    taxType: 'ITBIS',
                    jurisdiction: $this->rule->destination_country,
                    rate: '0',
                    taxableAmount: '0',
                    taxAmount: '0',
                    taxabilityReason: 'shipping_not_taxable',
                );

                continue;
            }

            $taxable = Money::roundCollect(Money::of($line->subtotal()));
            $taxAmount = Money::roundCollect($taxable->multipliedBy($rate));

            $totalTaxable = $totalTaxable->plus($taxable);
            $totalTax = $totalTax->plus($taxAmount);

            $components[] = new TaxComponent(
                lineId: $line->lineId,
                taxType: 'ITBIS',
                jurisdiction: $this->rule->destination_country,
                rate: (string) $rate,
                taxableAmount: (string) $taxable,
                taxAmount: (string) $taxAmount,
                taxabilityReason: 'taxable',
            );
        }

        $totalTaxRounded = Money::roundCollect($totalTax);
        $totalTaxableRounded = Money::roundCollect($totalTaxable);

        return new TaxResult(
            treatment: TaxTreatmentEnum::TAXED,
            totalTax: (string) $totalTaxRounded,
            totalTaxable: (string) $totalTaxableRounded,
            components: $components,
        );
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
            provider: TaxProviderEnum::MANUAL->value,
            isSupported: true,
        );
    }
}
