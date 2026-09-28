<?php

declare(strict_types=1);

namespace Tests\Connectors\ScrapperApi;

use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Collection;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\ScrapperApi\Actions\CalculateCustomTaxAction;
use Kanvas\Connectors\ScrapperApi\Enums\CustomTaxEnum;
use Kanvas\Connectors\ScrapperApi\Enums\ShippingCostEnum;
use Kanvas\Inventory\Channels\Models\Channels;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Inventory\Variants\Models\Variants;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

class CalculateCustomTaxActionTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private const EXCHANGE_RATE = 60.0;

    protected function setUp(): void
    {
        parent::setUp();

        $container = new Container();
        $container->instance('config', new Repository(['app.locale' => 'en']));
        $container->instance('events', new Dispatcher($container));
        Container::setInstance($container);
    }

    public function testAppliesTheAppendixOneFormula(): void
    {
        // CIF   = (100 x 2) + 30 freight + 5 insurance = 235
        // A     = 235 x 20%                            = 47.00
        // ITBIS = (235 + 47) x 18%                     = 50.76
        // TI                                           =  97.76
        $result = new CalculateCustomTaxAction(
            $this->variantFor('6109.10.00', 100.0),
            2.0,
            30.0,
            5.0,
        )->execute();

        $this->assertSame(235.0, $result['cif']);
        $this->assertSame(47.0, $result['arancel']);
        $this->assertSame(50.76, $result['itbis']);
        $this->assertSame(0.0, $result['tasaAduanal']);
        $this->assertSame(0.0, $result['isc']);
        $this->assertSame(97.76, $result['customTax']);
    }

    public function testConvertsEveryComponentToDominicanPesos(): void
    {
        $result = new CalculateCustomTaxAction($this->variantFor('6109.10.00', 100.0), 1.0)->execute();

        $this->assertSame(100.0, $result['cif']);
        $this->assertSame(6000.0, $result['cifRD']);
        $this->assertSame(round($result['customTax'] * self::EXCHANGE_RATE, 2), $result['customTaxRD']);
        $this->assertSame(round($result['arancel'] * self::EXCHANGE_RATE, 2), $result['arancelRD']);
    }

    /**
     * Laptops carry a 0% duty but are NOT ITBIS exempt, so the 18% still lands on the
     * bare CIF. Collapsing "duty free" into "tax free" is the expensive mistake here.
     */
    public function testDutyFreeGoodsStillPayItbis(): void
    {
        $result = new CalculateCustomTaxAction($this->variantFor('8471.30.00', 1000.0), 1.0)->execute();

        $this->assertSame(0.0, $result['arancel']);
        $this->assertSame(180.0, $result['itbis']);
        $this->assertSame(0.0, $result['tasaAduanal']);
        $this->assertSame(180.0, $result['customTax']);
    }

    public function testItbisExemptGoodsPayNoItbis(): void
    {
        $result = new CalculateCustomTaxAction($this->variantFor('4901.99.00', 100.0), 1.0)->execute();

        $this->assertSame(0.0, $result['arancel']);
        $this->assertSame(0.0, $result['itbis']);
        $this->assertSame(0.0, $result['itbisRate']);
        $this->assertSame(0.0, $result['customTax']);
    }

    public function testExcludesFreightFromCifWhenConfigured(): void
    {
        $variant = $this->variantFor('6109.10.00', 100.0, [
            CustomTaxEnum::INCLUDE_FREIGHT_IN_CIF->value => false,
        ]);

        $result = new CalculateCustomTaxAction(
            $variant,
            1.0,
            30.0,
            5.0,
        )->execute();

        $this->assertSame(100.0, $result['cif']);
    }

    public function testAppliesExciseTaxOnTopOfDuty(): void
    {
        // S = (CIF + A) x rate, per Appendix I.
        $variant = $this->variantFor('6109.10.00', 100.0, [
            CustomTaxEnum::ISC_RATES->value => ['6109' => 10],
        ]);

        $result = new CalculateCustomTaxAction($variant, 1.0)->execute();

        $this->assertSame(20.0, $result['arancel']);
        $this->assertSame(12.0, $result['isc'], '(100 + 20) x 10%');
        $this->assertSame(23.76, $result['itbis'], '(100 + 20 + 12) x 18%');
    }

    public function testReturnsZeroedResultWhenDisabled(): void
    {
        $variant = $this->variantFor('6109.10.00', 100.0, [
            ShippingCostEnum::CUSTOM_TAX_ENABLED->value => false,
        ]);

        $result = new CalculateCustomTaxAction($variant, 1.0)->execute();

        $this->assertSame(0.0, $result['customTax']);
        $this->assertNull($result['arancelCode']);
        $this->assertSame('Custom tax calculation disabled', $result['calculation']);
    }

    public function testFallsBackToConservativeDutyWhenUnclassifiable(): void
    {
        $result = new CalculateCustomTaxAction(
            $this->variantFor(null, 100.0, [], 'Zzzqx Unmatchable Widget'),
            1.0,
        )->execute();

        $this->assertNull($result['arancelCode']);
        $this->assertSame('fallback', $result['arancelSource']);
        $this->assertSame(20.0, $result['arancelRate']);
        $this->assertSame(20.0, $result['arancel']);
    }

    public function testClassifiesByKeywordWhenNoCachedCode(): void
    {
        $result = new CalculateCustomTaxAction(
            $this->variantFor(null, 100.0, [], 'Sony Wireless Noise Canceling Headphones'),
            1.0,
        )->execute();

        $this->assertSame('8518.30.00', $result['arancelCode']);
        $this->assertSame('keyword', $result['arancelSource']);
        $this->assertSame(14.0, $result['arancelRate']);
    }

    private function variantFor(
        ?string $arancelCode,
        float $price,
        array $settings = [],
        string $productName = 'Test Product'
    ): Variants {
        $settings = array_merge([
            ShippingCostEnum::CUSTOM_TAX_ENABLED->value => true,
            CustomTaxEnum::EXCHANGE_RATE->value => self::EXCHANGE_RATE,
        ], $settings);

        $app = Mockery::mock(Apps::class)->makePartial();
        $app->shouldReceive('get')->andReturnUsing(fn (string $key) => $settings[$key] ?? null);

        $product = Mockery::mock(Products::class)->makePartial();
        $product->shouldReceive('get')->andReturnUsing(
            fn (string $key) => $key === CustomTaxEnum::PRODUCT_ARANCEL_CODE->value ? $arancelCode : null
        );
        $product->shouldReceive('getId')->andReturn(1);
        $product->name = $productName;
        $product->description = '';
        $product->setRelation('categories', new Collection());

        $channel = Mockery::mock(Channels::class)->makePartial();
        $channel->price = $price;

        $variant = Mockery::mock(Variants::class)->makePartial();
        $variant->shouldReceive('getPriceInfoFromDefaultChannel')->andReturn($channel);
        $variant->setRelation('app', $app);
        $variant->setRelation('product', $product);

        return $variant;
    }
}
