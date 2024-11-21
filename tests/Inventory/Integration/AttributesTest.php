<?php

declare(strict_types=1);

namespace Tests\Inventory\Integration;

use Kanvas\Apps\Models\Apps;
use Kanvas\Inventory\Products\Actions\CreateProductAction;
use Kanvas\Inventory\Products\DataTransferObject\Product;
use Kanvas\Inventory\Support\Setup;
use Kanvas\Regions\Models\Regions;
use Tests\TestCase;

final class AttributesTest extends TestCase
{
    public function testGetAttributesFromProduct()
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        $setupInventory = new Setup($app, $user, $company);
        $setupInventory->run();

        $region = Regions::fromApp($app)->fromCompany($company)->first();

        $sku = 'model_price_01';

        $productData = new Product(
            app: $app,
            company: $company,
            user: $user,
            name: fake()->name,
            sku: $sku,
            warehouses: [[
                'quantity' => 10,
                'price' => 0.29,
            ],
            ]
        );

        $product = (new CreateProductAction($productData, $user))->execute();
        $attributeName = 'test_attribute';
        $attributeValue = 'test_value';
        $product->addAttribute($attributeName, $attributeValue);

        $this->assertEquals($product->getAttributeByName($attributeName)->value, $attributeValue);
    }
}
