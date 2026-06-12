<?php

declare(strict_types=1);

namespace Tests\GraphQL\Souk;

class ChildOrderTest extends OrderBase
{
    protected string $variantId;

    public function setUp(): void
    {
        parent::setUp();

        $productResponse = $this->createProduct()->json()['data']['createProduct'];

        $variantResponse = $this->createVariant(
            productId: $productResponse['id'],
            warehouseData: [
                'id' => $this->warehouseResponse['id'],
            ]
        )->json()['data']['createVariant'];

        $this->addVariantToChannel(
            variantId: $variantResponse['id'],
            channelId: $this->channelResponse['id'],
            warehouseData: [
                'id' => $this->warehouseResponse['id'],
            ]
        );

        $this->addVariantToWarehouse(
            variantId: $variantResponse['id'],
            warehouseId: $this->warehouseResponse['id'],
            amount: 100
        );

        $this->variantId = $variantResponse['id'];
    }

    public function testCreateOrderFromCartLinksParent(): void
    {
        $parent = $this->createOrderFromCart(
            metadata: ['data' => []],
            variantId: $this->variantId,
            orderType: 'roadside_assistance',
        );

        $this->assertNull($parent->parent_id);

        $child = $this->createOrderFromCart(
            metadata: ['data' => []],
            variantId: $this->variantId,
            orderType: 'roadside_assistance',
            parentId: $parent->getId(),
        );

        $this->assertEquals($parent->getId(), $child->parent_id);
        $this->assertNotEquals($parent->getId(), $child->getId());
    }

    public function testCreateOrderFromCartWithoutParentStaysRoot(): void
    {
        $order = $this->createOrderFromCart(
            metadata: ['data' => []],
            variantId: $this->variantId,
        );

        $this->assertNull($order->parent_id);
    }
}
