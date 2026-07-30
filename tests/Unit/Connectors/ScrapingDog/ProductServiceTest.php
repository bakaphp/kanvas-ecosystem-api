<?php

declare(strict_types=1);

namespace Tests\Unit\Connectors\ScrapingDog;

use Kanvas\Connectors\ScrapingDog\Services\ProductService;
use PHPUnit\Framework\TestCase;

final class ProductServiceTest extends TestCase
{
    public function testItMapsListAndSalePricesFromDedicatedFields(): void
    {
        $prices = $this->service()->prices([
            'price' => '$129.99',
            'list_price' => '$199.99',
        ]);

        $this->assertSame(199.99, $prices['price']);
        $this->assertSame(129.99, $prices['discountPrice']);
    }

    public function testItPreservesSalePriceWhenOriginalPriceIsDerivedFromSavings(): void
    {
        $prices = $this->service()->prices([
            'price' => '$129.99 with 35 percent savings',
        ]);

        $this->assertSame(199.98, $prices['price']);
        $this->assertSame(129.99, $prices['discountPrice']);
    }

    public function testItUsesCurrentPriceForBothFieldsWhenProductIsNotOnSale(): void
    {
        $prices = $this->service()->prices([
            'price' => '$129.99',
        ]);

        $this->assertSame(129.99, $prices['price']);
        $this->assertSame(129.99, $prices['discountPrice']);
    }

    public function testItCalculatesCostsFromTheEffectiveSalePrice(): void
    {
        $result = $this->service()->calcDiscountPrice([
            'price' => '$129.99',
            'list_price' => '$199.99',
        ]);

        $this->assertGreaterThan(129.99, $result['total']);
        $this->assertLessThan(129.99, $result['discount']);
    }

    private function service(): ProductServicePriceTestHarness
    {
        return new ProductServicePriceTestHarness();
    }
}

final class ProductServicePriceTestHarness extends ProductService
{
    public function __construct()
    {
    }

    /**
     * @return array{price: float, discountPrice: float}
     */
    public function prices(array $product): array
    {
        return $this->extractPrices($product);
    }
}
