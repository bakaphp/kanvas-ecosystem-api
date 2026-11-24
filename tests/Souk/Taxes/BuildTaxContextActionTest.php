<?php

declare(strict_types=1);

namespace Tests\Souk\Taxes;

use Kanvas\Souk\Taxes\DataTransferObject\TaxableLine;
use Kanvas\Souk\Taxes\DataTransferObject\TaxAddress;
use Kanvas\Souk\Taxes\DataTransferObject\TaxContext;
use Tests\TestCaseUnit;

final class BuildTaxContextActionTest extends TestCaseUnit
{
    private function makeContext(array $lineOverrides = []): TaxContext
    {
        $line = new TaxableLine(
            lineId: 'variant-uuid-1',
            reference: 'item-uuid-1',
            unitPrice: array_key_exists('unitPrice', $lineOverrides) ? $lineOverrides['unitPrice'] : '100.00',
            quantity: array_key_exists('quantity', $lineOverrides) ? $lineOverrides['quantity'] : 1,
            isTaxable: array_key_exists('isTaxable', $lineOverrides) ? $lineOverrides['isTaxable'] : true,
            taxCategory: $lineOverrides['taxCategory'] ?? null,
            taxCodeOverride: $lineOverrides['taxCodeOverride'] ?? null,
        );

        return new TaxContext(
            appId: '1',
            shipTo: new TaxAddress(country: 'DO', state: null, city: 'Santo Domingo', postalCode: null),
            shipFrom: null,
            lines: [$line],
            currency: 'DOP',
            cartSessionId: 'cart-session-1',
            reference: 'order-uuid-1',
            taxDate: '2026-10-24',
        );
    }

    public function testInputHashIsDeterministicForSameInputs(): void
    {
        $contextA = $this->makeContext();
        $contextB = $this->makeContext();

        $this->assertSame($contextA->inputHash(), $contextB->inputHash());
    }

    public function testInputHashChangesWhenUnitPriceChanges(): void
    {
        $original = $this->makeContext(['unitPrice' => '100.00']);
        $changed = $this->makeContext(['unitPrice' => '200.00']);

        $this->assertNotSame($original->inputHash(), $changed->inputHash());
    }

    public function testInputHashChangesWhenQuantityChanges(): void
    {
        $original = $this->makeContext(['quantity' => 1]);
        $changed = $this->makeContext(['quantity' => 3]);

        $this->assertNotSame($original->inputHash(), $changed->inputHash());
    }

    public function testInputHashChangesWhenIsTaxableChanges(): void
    {
        $taxable = $this->makeContext(['isTaxable' => true]);
        $exempt = $this->makeContext(['isTaxable' => false]);

        $this->assertNotSame($taxable->inputHash(), $exempt->inputHash());
    }

    public function testInputHashChangesWhenTaxCategoryChanges(): void
    {
        $general = $this->makeContext(['taxCategory' => 'general_tangible']);
        $digital = $this->makeContext(['taxCategory' => 'digital_goods']);

        $this->assertNotSame($general->inputHash(), $digital->inputHash());
    }

    public function testInputHashChangesWhenTaxCodeOverrideChanges(): void
    {
        $noCode = $this->makeContext(['taxCodeOverride' => null]);
        $withCode = $this->makeContext(['taxCodeOverride' => 'txcd_99999999']);

        $this->assertNotSame($noCode->inputHash(), $withCode->inputHash());
    }

    public function testEstimateAndFinalShareSameHashWhenInputsUnchanged(): void
    {
        $estimate = $this->makeContext();
        $final = $this->makeContext();

        $this->assertSame(
            $estimate->inputHash(),
            $final->inputHash(),
            'The same cart inputs must produce the same hash regardless of when the context is built.'
        );
    }

    public function testInputHashIsA64CharHexString(): void
    {
        $context = $this->makeContext();

        $hash = $context->inputHash();

        $this->assertSame(64, strlen($hash));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $hash);
    }

    public function testInputHashChangesWhenShipToCountryChanges(): void
    {
        $do = new TaxContext(
            appId: '1',
            shipTo: new TaxAddress(country: 'DO', state: null, city: null, postalCode: null),
            shipFrom: null,
            lines: [],
            currency: 'DOP',
            taxDate: '2026-10-24',
        );

        $us = new TaxContext(
            appId: '1',
            shipTo: new TaxAddress(country: 'US', state: 'NY', city: null, postalCode: '10001'),
            shipFrom: null,
            lines: [],
            currency: 'DOP',
            taxDate: '2026-10-24',
        );

        $this->assertNotSame($do->inputHash(), $us->inputHash());
    }

    public function testInputHashChangesWhenExemptionChanges(): void
    {
        $lines = [new TaxableLine(
            lineId: 'v1',
            reference: 'i1',
            unitPrice: '100.00',
            quantity: 1,
            isTaxable: true,
        )];

        $noExemption = new TaxContext(
            appId: '1',
            shipTo: new TaxAddress(country: 'DO', state: null, city: null, postalCode: null),
            shipFrom: null,
            lines: $lines,
            currency: 'DOP',
            taxDate: '2026-10-24',
        );

        $withExemption = new TaxContext(
            appId: '1',
            shipTo: new TaxAddress(country: 'DO', state: null, city: null, postalCode: null),
            shipFrom: null,
            lines: $lines,
            currency: 'DOP',
            exemptionType: 'resale',
            customerId: '42',
            taxDate: '2026-10-24',
        );

        $this->assertNotSame($noExemption->inputHash(), $withExemption->inputHash());
    }
}
