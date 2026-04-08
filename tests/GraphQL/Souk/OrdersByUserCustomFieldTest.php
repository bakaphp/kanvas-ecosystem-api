<?php

declare(strict_types=1);

namespace Tests\GraphQL\Souk;

use Illuminate\Support\Facades\Auth;

class OrdersByUserCustomFieldTest extends OrderBase
{
    protected string $variantId;

    public function setUp(): void
    {
        parent::setUp();

        $productResponse = $this->createProduct(attributes: [
            ['name' => 'slots', 'value' => 100],
        ])->json()['data']['createProduct'];

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
            amount: 100,
        );

        $this->variantId = $variantResponse['id'];
    }

    public function testOrdersByUserCustomField(): void
    {
        $user = Auth::user();
        $customFieldName = 'test_user_ref_' . fake()->lexify('????');

        $order = $this->createOrderFromCart([], $this->variantId);
        $order->set($customFieldName, (string) $user->getId());

        $response = $this->graphQL('
            query($custom_field_name: String!) {
                ordersByUserCustomField(first: 25, custom_field_name: $custom_field_name) {
                    data {
                        id
                    }
                }
            }
        ', ['custom_field_name' => $customFieldName]);

        $response->assertSuccessful();
        $data = $response->json('data.ordersByUserCustomField.data');
        $this->assertIsArray($data);
        $this->assertNotEmpty($data);
        $this->assertEquals((string) $order->getId(), $data[0]['id']);
    }

    public function testOrdersByUserCustomFieldWithUserId(): void
    {
        $user = Auth::user();
        $customFieldName = 'test_user_ref_admin_' . fake()->lexify('????');

        $order = $this->createOrderFromCart([], $this->variantId);
        $order->set($customFieldName, (string) $user->getId());

        $response = $this->graphQL('
            query($custom_field_name: String!, $user_id: ID) {
                ordersByUserCustomField(first: 25, custom_field_name: $custom_field_name, user_id: $user_id) {
                    data {
                        id
                    }
                }
            }
        ', [
            'custom_field_name' => $customFieldName,
            'user_id' => $user->getId(),
        ]);

        $response->assertSuccessful();
        $data = $response->json('data.ordersByUserCustomField.data');
        $this->assertIsArray($data);
        $this->assertNotEmpty($data);
        $this->assertEquals((string) $order->getId(), $data[0]['id']);
    }
}
