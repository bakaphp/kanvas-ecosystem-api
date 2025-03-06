<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\NetSuite;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\NetSuite\DataTransferObject\NetSuite;
use Kanvas\Connectors\NetSuite\Enums\CustomFieldEnum;
use Kanvas\Connectors\NetSuite\Services\NetSuiteProductService;
use Kanvas\Connectors\NetSuite\Services\NetSuiteServices;
use Tests\TestCase;

final class ProductTest extends TestCase
{
    public function testSetup()
    {
        $app = app(Apps::class);
        $company = Companies::first();
        $data = new NetSuite(
            app: $app,
            company: $company,
            account: getenv('NET_SUITE_ACCOUNT'),
            consumerKey: getenv('NET_SUITE_CONSUMER_KEY'),
            consumerSecret: getenv('NET_SUITE_CONSUMER_SECRET'),
            token: getenv('NET_SUITE_TOKEN'),
            tokenSecret: getenv('NET_SUITE_TOKEN_SECRET')
        );

        $result = NetSuiteServices::setup($data);

        $this->assertTrue($result);
    }

    public function testSearchProductInformation()
    {
        $company = Companies::first();
        $app = app(Apps::class);

        $productService = new NetSuiteProductService($app, $company);
        $product = $productService->searchProductByItemNumber(getenv('NET_SUITE_ITEM_NUMBER'));

        $this->assertIsArray($product);
        $this->assertNotEmpty($product);
        $this->assertNotEmpty($product[0]->itemId);
    }

    public function testGetProductById()
    {
        $company = Companies::first();
        $app = app(Apps::class);

        $productService = new NetSuiteProductService($app, $company);
        $product = $productService->searchProductByItemNumber(getenv('NET_SUITE_ITEM_NUMBER'));
        $product = $productService->getProductById($product[0]->internalId);

        $this->assertIsObject($product);
        $this->assertNotEmpty($product->itemId);
    }

    public function testGetProductQuantityBylocation()
    {
        $company = Companies::first();
        $app = app(Apps::class);

        $productService = new NetSuiteProductService($app, $company);
        $product = $productService->searchProductByItemNumber(getenv('NET_SUITE_ITEM_NUMBER'));
        $product = $productService->getProductById($product[0]->internalId);
        $quantity = $productService->getInventoryQuantityByLocation($product, getenv('NET_SUITE_LOCATION_ID'));

        $this->assertIsInt($quantity);
        $this->assertGreaterThan(0, $quantity);
    }

    public function testGetProductPrice()
    {
        $company = Companies::first();
        $app = app(Apps::class);

        $productService = new NetSuiteProductService($app, $company);
        $product = $productService->searchProductByItemNumber(getenv('NET_SUITE_ITEM_NUMBER'));

        $product = $productService->getProductById($product[0]->internalId);
        $price = $productService->getProductPrice($product);

        $this->assertIsFloat($price);
        $this->assertGreaterThan(0, $price);
    }

    public function testGetProductMapPrice()
    {
        $company = Companies::first();
        $app = app(Apps::class);

        $productService = new NetSuiteProductService($app, $company);
        $product = $productService->searchProductByItemNumber(getenv('NET_SUITE_ITEM_NUMBER'));

        $product = $productService->getProductById($product[0]->internalId);
        $price = $productService->getProductMapPrice($product, CustomFieldEnum::NET_SUITE_MAP_PRICE_CUSTOM_FIELD->value);

        $this->assertIsFloat($price);
        $this->assertGreaterThan(0, $price);
    }
}
