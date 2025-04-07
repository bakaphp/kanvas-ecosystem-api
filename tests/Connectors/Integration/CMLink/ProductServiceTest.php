<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\CMLink;

use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\CMLink\Services\CMLinkProductService;
use Kanvas\Inventory\Support\Setup;
use Kanvas\Regions\Models\Regions;
use Tests\TestCase;

final class ProductServiceTest extends TestCase
{
    public function testProductMapping(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        $inventory = (new Setup($app, $user, $company))->run();

        $region = Regions::getDefault($company, $app);

        $bundles = json_decode('{"code":"0000000","description":"Success","dataBundles":[{"id":"TST001","name":[{"langInfo":{"language":"en","country":"US"},"value":"TestRegion 7 Days 1GB Plan"}],"desc":[{"langInfo":{"language":"en","country":"US"},"value":"This plan provides 1GB of data for 7 days. Perfect for short trips and light internet usage."}],"priceInfo":[{"currencyCode":"840","price":"1.00"}],"originalPriceInfo":[{"currencyCode":"840","price":"2.00"}],"status":1,"type":1,"period":7,"imgurl":"http://example.com/image1.jpg","variants":[{"name":"Test Region 7-Day 1GB Plan - 1GB/day for 7 days","price":"1.00","sku":"TST001-1GB-7D"},{"name":"Test Region 7-Day 1GB Plan - 2GB/day for 7 days","price":"1.50","sku":"TST001-2GB-7D"}]},{"id":"TST002","name":[{"langInfo":{"language":"en","country":"US"},"value":"TestRegion 15 Days 2GB Plan"}],"desc":[{"langInfo":{"language":"en","country":"US"},"value":"This plan offers 2GB of daily data for 15 days. Ideal for medium-duration trips."}],"priceInfo":[{"currencyCode":"840","price":"2.00"}],"originalPriceInfo":[{"currencyCode":"840","price":"3.00"}],"status":1,"type":1,"period":15,"imgurl":"http://example.com/image2.jpg","variants":[{"name":"Test Region 15-Day 2GB Plan - 2GB/day for 15 days","price":"2.00","sku":"TST002-2GB-15D"},{"name":"Test Region 15-Day 2GB Plan - 3GB/day for 15 days","price":"2.50","sku":"TST002-3GB-15D"}]},{"id":"TST003","name":[{"langInfo":{"language":"en","country":"US"},"value":"TestRegion2 30 Days 5GB Plan"}],"desc":[{"langInfo":{"language":"en","country":"US"},"value":"Enjoy 5GB daily data for 30 days. Suited for extended usage."}],"priceInfo":[{"currencyCode":"840","price":"3.00"}],"originalPriceInfo":[{"currencyCode":"840","price":"4.00"}],"status":1,"type":1,"period":30,"imgurl":"http://example.com/image3.jpg","variants":[{"name":"Test Region 30-Day 5GB Plan - 5GB/day for 30 days","price":"3.00","sku":"TST003-5GB-30D"},{"name":"Test Region 30-Day 5GB Plan - 10GB/day for 30 days","price":"3.50","sku":"TST003-10GB-30D"}]},{"id":"TST004","name":[{"langInfo":{"language":"en","country":"US"},"value":"TestRegion2 60 Days Unlimited Plan"}],"desc":[{"langInfo":{"language":"en","country":"US"},"value":"Unlimited data for 60 days. The best choice for heavy internet users."}],"priceInfo":[{"currencyCode":"840","price":"4.00"}],"originalPriceInfo":[{"currencyCode":"840","price":"5.00"}],"status":1,"type":1,"period":60,"imgurl":"http://example.com/image4.jpg","variants":[{"name":"Test Region 60-Day Unlimited Plan - Unlimited data for 60 days","price":"4.00","sku":"TST004-UNLIM-60D"},{"name":"Test Region 60-Day Unlimited Plan - Unlimited data for 30 days","price":"3.00","sku":"TST004-UNLIM-30D"}]}]}', true);
        $cmlinkProductService = new CMLinkProductService($region);

        $products = $cmlinkProductService->mapProductToImport($bundles);

        $this->assertIsArray($products);
        $this->assertCount(2, $products);
        $this->assertArrayHasKey('name', $products[0]);
        $this->assertCount(2, $products[0]['variants']);
        $this->assertArrayHasKey('name', $products[0]['variants'][0]);
    }
}
