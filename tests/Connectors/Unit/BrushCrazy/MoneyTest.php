<?php

declare(strict_types=1);

namespace Tests\Connectors\Unit\BrushCrazy;

use Kanvas\Connectors\BrushCrazy\Support\Money;
use Tests\TestCase;

final class MoneyTest extends TestCase
{
    public function testDecodesMinorUnitsFromJson(): void
    {
        $money = Money::fromRaw('{"amount":2500,"currency":"USD"}');

        $this->assertSame(2500, $money->cents);
        $this->assertSame(25.0, $money->dollars());
        $this->assertSame('USD', $money->currency);
    }

    public function testDecodesAmountStoredAsString(): void
    {
        $this->assertSame(4500, Money::fromRaw('{"amount":"4500","currency":"USD"}')->cents);
    }

    public function testAcceptsAlreadyDecodedArray(): void
    {
        $money = Money::fromRaw(['amount' => 1999, 'currency' => 'CAD']);

        $this->assertSame(1999, $money->cents);
        $this->assertSame('CAD', $money->currency);
    }

    /**
     * Real shape of `sale_items.discount` in production — a discount object wrapping a Money,
     * with the inner amount stored as a string. Casting the outer `amount` directly would read as
     * 1 cent, since `(int) []` is 1.
     */
    public function testUnwrapsNestedAmountFromDiscountShape(): void
    {
        $discount = '{"name":null,"type":"fixed","amount":{"amount":"0","currency":"USD"},"reason":null}';

        $this->assertTrue(Money::fromRaw($discount)->isZero());

        $applied = '{"name":"Comp","type":"fixed","amount":{"amount":"1250","currency":"USD"},"reason":null}';

        $this->assertSame(1250, Money::fromRaw($applied)->cents);
        $this->assertSame(12.5, Money::fromRaw($applied)->dollars());
    }

    public function testDefaultsCurrencyWhenMissing(): void
    {
        $this->assertSame('USD', Money::fromRaw('{"amount":100}')->currency);
    }

    /**
     * The source cast treats blank and unparseable values as zero rather than erroring, and the
     * legacy tables hold both. Diverging here would abort an import on a dirty row.
     */
    public function testBlankAndUnparseableValuesAreZero(): void
    {
        $this->assertTrue(Money::fromRaw(null)->isZero());
        $this->assertTrue(Money::fromRaw('')->isZero());
        $this->assertTrue(Money::fromRaw('   ')->isZero());
        $this->assertTrue(Money::fromRaw('not json')->isZero());
    }

    /**
     * A bare numeric string decodes to an int, not an array — the source returns zero for it, so a
     * price column holding "2500" instead of the JSON shape must not silently read as $25.
     */
    public function testScalarJsonIsZeroNotAnAmount(): void
    {
        $this->assertTrue(Money::fromRaw('2500')->isZero());
    }

    public function testSlugComponentUsesCentsSoItNeverDriftsOnFloatFormatting(): void
    {
        $this->assertSame('2500', Money::fromRaw('{"amount":2500,"currency":"USD"}')->slugComponent());
        $this->assertSame('0', Money::fromRaw(null)->slugComponent());
    }
}
