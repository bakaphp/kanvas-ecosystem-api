<?php

declare(strict_types=1);

namespace Tests\Connectors\Unit\BrushCrazy;

use Kanvas\Connectors\BrushCrazy\Support\AddonPricer;
use Tests\TestCase;

final class AddonPricerTest extends TestCase
{
    public function testFixedAddonIsPricedOnlyFromAMapKey(): void
    {
        $pricer = AddonPricer::fromRows([$this->setting('Sketch Fee', 'fixed', $this->money(300))]);

        $this->assertSame(300, $pricer->unitCents($this->item(['addons' => '{"Sketch Fee":true}'])));
        $this->assertSame(0, $pricer->unitCents($this->item(['addons' => '["Sketch Fee"]'])));
        $this->assertSame(0, $pricer->unitCents($this->item(['addons' => null])));
    }

    /** Bubble Art prices off "Bubble Art Formula", and a tagged formula is "tag|expression". */
    public function testFormulaAddonUsesTheUnitPriceInDollars(): void
    {
        $pricer = AddonPricer::fromRows([$this->formula('Bubble Art Formula', 'product,special|a * 0.25')]);

        $this->assertSame(
            [['name' => 'Bubble Art', 'cents' => 1000]],
            $pricer->breakdown($this->item(['price' => $this->money(4000), 'addons' => '{"Bubble Art":true}']))
        );
    }

    /** mossadal/math-parser reads `^` as power and `2a` as 2·a, and accepts `.25`. */
    public function testFormulaSyntaxOfTheSourceParser(): void
    {
        $item = $this->item(['price' => $this->money(300), 'addons' => '{"Mosaic":true}']);

        $this->assertSame(900, AddonPricer::fromRows([$this->formula('Mosaic Formula', 'a^2')])->unitCents($item));
        $this->assertSame(600, AddonPricer::fromRows([$this->formula('Mosaic Formula', '2a')])->unitCents($item));
        $this->assertSame(75, AddonPricer::fromRows([$this->formula('Mosaic Formula', '.25*a')])->unitCents($item));
    }

    /** The source parser's number token takes a comma as the decimal point. */
    public function testCommaDecimalIsADecimalPoint(): void
    {
        $item = $this->item(['price' => $this->money(300), 'addons' => '{"Mosaic":true}']);

        $this->assertSame(450, AddonPricer::fromRows([$this->formula('Mosaic Formula', 'a * 1,5')])->unitCents($item));
        $this->assertSame(75, AddonPricer::fromRows([$this->formula('Mosaic Formula', ',25a')])->unitCents($item));
    }

    /** A studio-typed formula that does not evaluate is free at the source, not an error. */
    public function testBrokenFormulaIsFree(): void
    {
        $item = $this->item(['addons' => '{"Mosaic":true}']);

        $this->assertSame(0, AddonPricer::fromRows([$this->formula('Mosaic Formula', 'a * (')])->unitCents($item));
        $this->assertSame(0, AddonPricer::fromRows([$this->formula('Mosaic Formula', 'sqrt(a)')])->unitCents($item));
        $this->assertSame(0, AddonPricer::fromRows([$this->formula('Mosaic Formula', 'a / 0')])->unitCents($item));
    }

    public function testStudioCreatedAddonCanBeAPercentOfThePrice(): void
    {
        $pricer = AddonPricer::fromRows([$this->setting('Glitter', 'percent', '{"percent":1500}')]);

        $this->assertSame(
            [['name' => 'Glitter', 'cents' => 375]],
            $pricer->breakdown($this->item(['price' => $this->money(2500), 'addons' => '{"Glitter":true}']))
        );
    }

    public function testCountedAddonsMultiplyTheirSetting(): void
    {
        $pricer = AddonPricer::fromRows([$this->setting('Extra Color', 'fixed', $this->money(100))]);

        $this->assertSame(
            [['name' => 'Extra Color x3', 'cents' => 300]],
            $pricer->breakdown($this->item(['extra_colors' => 3]))
        );
    }

    public function testDoubleSidedUsesItsFormula(): void
    {
        $pricer = AddonPricer::fromRows([$this->formula('Double-sided Formula', 'a * 0.5')]);

        $this->assertSame(1250, $pricer->unitCents($this->item(['double_sided' => 1])));
    }

    /** Settings are read live-first, so an archived row never shadows the live one of the same name. */
    public function testFirstSettingOfANameWins(): void
    {
        $pricer = AddonPricer::fromRows([
            $this->setting('Grout Fee', 'fixed', $this->money(700)),
            $this->setting('Grout Fee', 'fixed', $this->money(200)),
        ]);

        $this->assertSame(700, $pricer->unitCents($this->item(['addons' => '{"Grout Fee":true}'])));
    }

    /** A setting that no longer exists still shows the add-on was used, at zero. */
    public function testMissingSettingListsTheAddonAtZero(): void
    {
        $this->assertSame(
            [['name' => 'Wax Resist', 'cents' => 0]],
            AddonPricer::empty()->breakdown($this->item(['addons' => '{"Wax Resist":true}']))
        );
    }

    public function testPercentShapedAmountIsNotMoney(): void
    {
        $pricer = AddonPricer::fromRows([$this->setting('Gold Accent', 'fixed', '{"percent":1000}')]);

        $this->assertSame(0, $pricer->unitCents($this->item(['addons' => '{"Gold Accent":true}'])));
    }

    private function setting(string $name, string $type, ?string $amount): object
    {
        return (object) ['name' => $name, 'type' => $type, 'amount' => $amount, 'value' => null];
    }

    private function formula(string $name, string $expression): object
    {
        return (object) ['name' => $name, 'type' => 'formula', 'amount' => null, 'value' => $expression];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function item(array $overrides = []): object
    {
        return (object) ($overrides + [
            'price' => $this->money(2500),
            'addons' => null,
            'extra_colors' => 0,
            'extra_gold' => 0,
            'extra_silver' => 0,
            'double_sided' => 0,
        ]);
    }

    private function money(int $cents): string
    {
        return '{"amount":' . $cents . ',"currency":"USD"}';
    }
}
