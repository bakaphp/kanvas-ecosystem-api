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
    /**
     * @param TaxRoutingRule[] $rules  One per applicable tax (e.g. ITBIS + ISC).
     *                                  General rules (null category_codes) apply to every taxable line.
     *                                  Category-specific rules apply only to lines whose taxCategory
     *                                  appears in the rule's category_codes array.
     */
    public function __construct(
        private readonly array $rules,
        private readonly ?CustomerTaxExemption $exemption = null,
    ) {
    }

    public function calculate(TaxContext $context): TaxResult
    {
        $firstRule = $this->rules[0] ?? new TaxRoutingRule();

        if ($this->exemption !== null) {
            $components = array_map(
                fn (TaxableLine $line) => new TaxComponent(
                    lineId: $line->lineId,
                    taxType: $firstRule->name ?? 'VAT',
                    jurisdiction: $firstRule->condition_country,
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

        $allComponents = [];
        $totalTax = BigDecimal::zero();
        $taxableByLine = []; // lineId => BigDecimal, deduplicated so the same line isn't double-counted

        foreach ($context->lines as $line) {
            if (! $line->isTaxable) {
                $allComponents[] = new TaxComponent(
                    lineId: $line->lineId,
                    taxType: $firstRule->name ?? 'VAT',
                    jurisdiction: $firstRule->condition_country,
                    rate: '0',
                    taxableAmount: '0',
                    taxAmount: '0',
                    taxabilityReason: 'product_not_taxable',
                );

                continue;
            }

            $lineMatchedAnyRule = false;

            foreach ($this->rules as $rule) {
                if (! $this->ruleAppliesToLine($rule, $line)) {
                    continue;
                }

                $lineMatchedAnyRule = true;
                $rate = Money::of((string) $rule->rate);
                $taxable = Money::roundCollect(Money::of($line->subtotal()));
                $taxAmount = Money::roundCollect($taxable->multipliedBy($rate));

                $taxableByLine[$line->lineId] = $taxable;
                $totalTax = $totalTax->plus($taxAmount);

                $allComponents[] = new TaxComponent(
                    lineId: $line->lineId,
                    taxType: $rule->name ?? 'VAT',
                    jurisdiction: $rule->condition_country,
                    rate: (string) $rate,
                    taxableAmount: (string) $taxable,
                    taxAmount: $taxAmount->isZero() ? '0' : (string) $taxAmount,
                    taxabilityReason: 'taxable',
                );
            }

            if (! $lineMatchedAnyRule) {
                $allComponents[] = new TaxComponent(
                    lineId: $line->lineId,
                    taxType: $firstRule->name ?? 'VAT',
                    jurisdiction: $firstRule->condition_country,
                    rate: '0',
                    taxableAmount: '0',
                    taxAmount: '0',
                    taxabilityReason: $line->isShipping ? 'shipping_not_taxable' : 'no_rule_match',
                );
            }
        }

        $totalTaxable = array_reduce(
            $taxableByLine,
            fn (BigDecimal $carry, BigDecimal $amount) => $carry->plus($amount),
            BigDecimal::zero(),
        );

        $totalTaxRounded = Money::roundCollect($totalTax);
        $totalTaxableRounded = Money::roundCollect($totalTaxable);

        return new TaxResult(
            treatment: TaxTreatmentEnum::TAXED,
            totalTax: $totalTaxRounded->isZero() ? '0' : (string) $totalTaxRounded,
            totalTaxable: $totalTaxableRounded->isZero() ? '0' : (string) $totalTaxableRounded,
            components: $allComponents,
        );
    }

    /**
     * General rules (empty category_codes) apply to every taxable line.
     * Category-specific rules apply only when the line's taxCategory is listed.
     * Shipping lines are skipped when the rule's tax_shipping flag is false.
     */
    private function ruleAppliesToLine(TaxRoutingRule $rule, TaxableLine $line): bool
    {
        if ($line->isShipping && ! $rule->tax_shipping) {
            return false;
        }

        $categoryCodes = $rule->category_codes;

        if (empty($categoryCodes)) {
            return true;
        }

        return $line->taxCategory !== null && in_array($line->taxCategory, $categoryCodes, strict: true);
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
