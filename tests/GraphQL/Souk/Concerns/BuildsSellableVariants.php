<?php

declare(strict_types=1);

namespace Tests\GraphQL\Souk\Concerns;

use Kanvas\Inventory\Variants\Models\Variants;

trait BuildsSellableVariants
{
    private function buildSellableVariant(int $stock = 10, array $productAttributes = []): Variants
    {
        $response = $this->createProduct(attributes: $productAttributes);
        $response->assertSuccessful();
        $productResponse = $response->json('data.createProduct');

        $variantResponse = $this->createVariant(
            productId: $productResponse['id'],
            warehouseData: ['id' => $this->warehouseResponse['id']],
        )->json()['data']['createVariant'];

        $this->addVariantToChannel(
            variantId: $variantResponse['id'],
            channelId: $this->channelResponse['id'],
            warehouseData: ['id' => $this->warehouseResponse['id']],
        );

        $this->addVariantToWarehouse(
            variantId: $variantResponse['id'],
            warehouseId: $this->warehouseResponse['id'],
            amount: $stock,
        );

        return Variants::findOrFail($variantResponse['id']);
    }
}
