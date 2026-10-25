<?php

declare(strict_types=1);

namespace Tests\Souk\Taxes;

use Kanvas\Souk\Taxes\DataTransferObject\TaxAddress;
use Kanvas\Souk\Taxes\DataTransferObject\TaxContext;
use Kanvas\Souk\Taxes\DataTransferObject\TaxReversal;
use Kanvas\Souk\Taxes\DataTransferObject\TaxableLine;
use Kanvas\Souk\Taxes\Enums\TaxTreatmentEnum;
use Kanvas\Souk\Taxes\Models\CustomerTaxExemption;
use Kanvas\Souk\Taxes\Models\OrderTaxSnapshot;
use Kanvas\Souk\Taxes\Models\TaxRoutingRule;
use Kanvas\Souk\Taxes\Providers\ManualRateTaxProvider;
use Kanvas\Souk\Taxes\Providers\NullTaxProvider;
use Tests\TestCaseUnit;

final class ManualRateTaxProviderTest extends TestCaseUnit
{
    private function makeRule(float $rate = 0.18, bool $taxShipping = false): TaxRoutingRule
    {
        $rule = new TaxRoutingRule();
        $rule->destination_country = 'DO';
        $rule->provider = 'manual';
        $rule->rate = $rate;
        $rule->tax_shipping = $taxShipping;
        $rule->priority = 100;

        return $rule;
    }

    private function makeContext(array $lineOverrides = [], ?string $customerId = null): TaxContext
    {
        $line = new TaxableLine(
            lineId: $lineOverrides['lineId'] ?? 'line-1',
            reference: $lineOverrides['reference'] ?? 'item-1',
            unitPrice: $lineOverrides['unitPrice'] ?? '100.00',
            quantity: $lineOverrides['quantity'] ?? 1,
            isTaxable: $lineOverrides['isTaxable'] ?? true,
            taxCategory: $lineOverrides['taxCategory'] ?? null,
            taxCodeOverride: null,
            isShipping: $lineOverrides['isShipping'] ?? false,
        );

        return new TaxContext(
            appId: '1',
            shipTo: new TaxAddress(country: 'DO', state: null, city: 'Santo Domingo', postalCode: null),
            shipFrom: null,
            lines: [$line],
            currency: 'DOP',
            taxDate: '2026-10-24',
            customerId: $customerId,
        );
    }

    public function testTaxableLineProducesCorrectTaxAmount(): void
    {
        $provider = new ManualRateTaxProvider(rule: $this->makeRule(0.18));

        $result = $provider->calculate($this->makeContext(['unitPrice' => '100.00', 'quantity' => 1]));

        $this->assertSame(TaxTreatmentEnum::TAXED, $result->treatment);
        $this->assertSame('18.00', $result->totalTax);
        $this->assertSame('100.00', $result->totalTaxable);
        $this->assertCount(1, $result->components);
        $this->assertSame('taxable', $result->components[0]->taxabilityReason);
        $this->assertSame('18.00', $result->components[0]->taxAmount);
    }

    public function testExemptCustomerProducesZeroTaxWithExemptTreatment(): void
    {
        $exemption = new CustomerTaxExemption();
        $exemption->exemption_type = 'resale';

        $provider = new ManualRateTaxProvider(rule: $this->makeRule(0.18), exemption: $exemption);

        $result = $provider->calculate($this->makeContext());

        $this->assertSame(TaxTreatmentEnum::EXEMPT, $result->treatment);
        $this->assertSame('0', $result->totalTax);
        $this->assertSame('0', $result->totalTaxable);
        $this->assertCount(1, $result->components);
        $this->assertSame('customer_exempt', $result->components[0]->taxabilityReason);
        $this->assertSame('0', $result->components[0]->taxAmount);
    }

    public function testNonTaxableProductProducesZeroComponent(): void
    {
        $provider = new ManualRateTaxProvider(rule: $this->makeRule(0.18));

        $result = $provider->calculate($this->makeContext(['isTaxable' => false]));

        $this->assertSame('0', $result->totalTax);
        $this->assertCount(1, $result->components);
        $this->assertSame('product_not_taxable', $result->components[0]->taxabilityReason);
        $this->assertSame('0', $result->components[0]->taxAmount);
    }

    public function testShippingNotTaxableWhenRuleFlagIsFalse(): void
    {
        $provider = new ManualRateTaxProvider(rule: $this->makeRule(0.18, taxShipping: false));

        $result = $provider->calculate($this->makeContext(['isShipping' => true, 'isTaxable' => true]));

        $this->assertSame('0', $result->totalTax);
        $this->assertCount(1, $result->components);
        $this->assertSame('shipping_not_taxable', $result->components[0]->taxabilityReason);
    }

    public function testShippingTaxableWhenRuleFlagIsTrue(): void
    {
        $provider = new ManualRateTaxProvider(rule: $this->makeRule(0.18, taxShipping: true));

        $result = $provider->calculate($this->makeContext([
            'isShipping' => true,
            'isTaxable' => true,
            'unitPrice' => '200.00',
            'quantity' => 1,
        ]));

        $this->assertSame(TaxTreatmentEnum::TAXED, $result->treatment);
        $this->assertSame('36.00', $result->totalTax);
        $this->assertSame('taxable', $result->components[0]->taxabilityReason);
    }

    public function testCommitIsANoOp(): void
    {
        $provider = new ManualRateTaxProvider(rule: $this->makeRule());
        $snapshot = new OrderTaxSnapshot();
        $snapshot->exists = true;

        $provider->commit($snapshot);

        $this->assertTrue(true);
    }

    public function testVoidIsANoOp(): void
    {
        $provider = new ManualRateTaxProvider(rule: $this->makeRule());
        $snapshot = new OrderTaxSnapshot();
        $snapshot->exists = true;

        $provider->void($snapshot);

        $this->assertTrue(true);
    }

    public function testReverseIsANoOp(): void
    {
        $provider = new ManualRateTaxProvider(rule: $this->makeRule());
        $snapshot = new OrderTaxSnapshot();
        $snapshot->exists = true;
        $reversal = new TaxReversal(
            snapshotUuid: 'uuid-1',
            amount: '100.00',
            taxAmount: '18.00',
        );

        $provider->reverse($snapshot, $reversal);

        $this->assertTrue(true);
    }

    public function testNullProviderCalculateReturnsNoBaseTreatment(): void
    {
        $provider = new NullTaxProvider();

        $result = $provider->calculate($this->makeContext());

        $this->assertSame(TaxTreatmentEnum::NO_BASE, $result->treatment);
        $this->assertSame('0', $result->totalTax);
        $this->assertSame('0', $result->totalTaxable);
        $this->assertEmpty($result->components);
    }
}
