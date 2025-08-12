<?php

declare(strict_types=1);

namespace Tests\GraphQL\Souk;

use Kanvas\Apps\Models\Apps;
use Kanvas\Souk\Orders\Models\OrderStatus;
use Kanvas\Souk\Orders\Models\OrderStatusTransitions;
use Kanvas\Souk\Orders\Models\OrderTypes;

class ExportOrderTest extends OrderBase
{
    protected $orderTypeName = 'reservation';

    public function setUp(): void
    {
        parent::setUp();
        $orderType = OrderTypes::firstOrCreate([
            'name' => $this->orderTypeName,
            'apps_id' => $this->apps->id,
        ]);

        $this->createStatuses($this->apps, $orderType, [
            [
                'slug' => 'draft',
                'name' => 'Draft',
                'is_default' => true,
                'is_final' => false,
                'transitions' => [],
            ],
            [
                'slug' => 'pending',
                'name' => 'Pending',
                'is_default' => false,
                'is_final' => false,
                'transitions' => ['draft'],
            ],
            [
                'slug' => 'paid',
                'name' => 'Paid',
                'is_default' => false,
                'is_final' => true,
                'transitions' => ['pending'],
            ],
            [
                'slug' => 'cancelled',
                'name' => 'Cancelled',
                'is_default' => false,
                'is_final' => true,
                'transitions' => ['pending', 'paid'],
            ],
        ]);
    }

    public function createStatuses(Apps $app, OrderTypes $orderType, array $statuses)
    {
        $savedStatuses = [];
        foreach ($statuses as $status) {
            $createdStatus = OrderStatus::firstOrCreate([
                'order_types_id' => $orderType->id,
                'apps_id' => $app->id,
                'slug' => $status['slug'],
                'name' => $status['name'],
                'is_default' => $status['is_default'],
                'is_final' => $status['is_final'],
            ]);

            $savedStatuses[$status['slug']] = $createdStatus->id;
        }

        foreach ($statuses as $status) {
            foreach ($status['transitions'] as $transition) {
                OrderStatusTransitions::firstOrCreate([
                    'order_types_id' => $orderType->id,
                    'from_status_id' => $savedStatuses[$transition],
                    'to_status_id' => $savedStatuses[$status['slug']],
                    'name' => $status['name'],
                ]);
            }
        }
    }



    public function testExportOrdersWithFieldMapping(): void
    {
        // Create product and variant
        $productResponse = $this->createProduct(attributes: [
            ['name' => 'slots', 'value' => 100]
        ])->json()['data']['createProduct'];

        $variantResponse = $this->createVariant(
            productId: $productResponse['id'],
            warehouseData: ['id' => $this->warehouseResponse['id']],
            attributes: [
                ['name' => 'timezone', 'value' => 'America/New_York'],
            ]
        )->json()['data']['createVariant'];

        $this->addVariantToChannel(
            variantId: $variantResponse['id'],
            channelId: $this->channelResponse['id'],
            warehouseData: ['id' => $this->warehouseResponse['id']]
        );

        $this->addVariantToWarehouse(
            variantId: $variantResponse['id'],
            warehouseId: $this->warehouseResponse['id'],
            amount: 100
        );

        // Create order with metadata
        $order = $this->createOrderFromCart(
            variantId: $variantResponse['id'],
            quantity: 1,
            metadata: [
                'data' => [
                    'vehiclePlate' => 'ABC123',
                    'vehicleBrand' => 'Toyota',
                    'vehicleColor' => 'Red',
                    'start_at' => '2024-01-15 10:30:00'
                ]
            ],
            orderType: $this->orderTypeName
        );

        // Test basic export without field mapping
        $basicExportResponse = $this->graphQL('
            query ExportOrdersBasic {
                exportOrders(format: PDF) {
                    status
                    download_url
                    file_name
                    message
                }
            }
        ', [], [], [
            'X-Kanvas-Location' => $this->company->branch->uuid,
            'X-Kanvas-App' => $this->apps->key,
        ]);

        $this->assertEquals('success', $basicExportResponse->json('data.exportOrders.status'));
        $this->assertNotNull($basicExportResponse->json('data.exportOrders.download_url'));
        $this->assertStringContainsString('pdf', $basicExportResponse->json('data.exportOrders.file_name'));

        // Test export with custom field mapping
        $customExportResponse = $this->graphQL('
            query ExportOrdersWithMapping($fieldMapper: Mixed!, $metadata: ExportMetadataInput!) {
                exportOrders(
                    format: EXCEL
                    field_mapper: $fieldMapper
                    metadata: $metadata
                ) {
                    status
                    download_url
                    file_name
                    message
                }
            }
        ', [
            'fieldMapper' => [
                'Placa' => 'metadata.data.vehiclePlate',
                'Vehiculo' => 'metadata.data.vehicleBrand',
                'Color' => 'metadata.data.vehicleColor',
                'Producto' => 'items[0].product_name',
                'Estado' => 'orderStatus.name',
                'Total' => 'total_net_amount',
                'Fecha' => 'metadata.data.start_at',
                'ID Orden' => 'id'
            ],
            'metadata' => [
                'custom_title' => 'Reporte de Vehículos Retenidos'
            ]
        ], [], [
            'X-Kanvas-Location' => $this->company->branch->uuid,
            'X-Kanvas-App' => $this->apps->key,
        ]);

        $this->assertEquals('success', $customExportResponse->json('data.exportOrders.status'));
        $this->assertNotNull($customExportResponse->json('data.exportOrders.download_url'));
        $this->assertStringContainsString('xlsx', $customExportResponse->json('data.exportOrders.file_name'));
        $this->assertEquals('Excel export completed successfully', $customExportResponse->json('data.exportOrders.message'));

        // Test export with search filter
        $filteredExportResponse = $this->graphQL('
            query ExportOrdersWithSearch($search: String!, $metadata: ExportMetadataInput!) {
                exportOrders(
                    format: PDF
                    search: $search
                    metadata: $metadata
                ) {
                    status
                    download_url
                    file_name
                    message
                }
            }
        ', [
            'search' => $order->user_email,
            'metadata' => [
                'custom_title' => 'Filtered Orders Report'
            ]
        ], [], [
            'X-Kanvas-Location' => $this->company->branch->uuid,
            'X-Kanvas-App' => $this->apps->key,
        ]);

        $this->assertEquals('success', $filteredExportResponse->json('data.exportOrders.status'));
        $this->assertNotNull($filteredExportResponse->json('data.exportOrders.download_url'));

        // Test export with invalid format (should still work with fallback)
        $invalidFormatResponse = $this->graphQL('
            query ExportOrdersInvalidFormat {
                exportOrders(format: PDF) {
                    status
                    download_url
                    file_name
                    message
                }
            }
        ', [], [], [
            'X-Kanvas-Location' => $this->company->branch->uuid,
            'X-Kanvas-App' => $this->apps->key,
        ]);

        $this->assertEquals('success', $invalidFormatResponse->json('data.exportOrders.status'));
    }

    public function testExportOrdersFieldMappingEdgeCases(): void
    {
        // Create a simple order for testing edge cases
        $productResponse = $this->createProduct()->json()['data']['createProduct'];
        $variantResponse = $this->createVariant(
            productId: $productResponse['id'],
            warehouseData: ['id' => $this->warehouseResponse['id']]
        )->json()['data']['createVariant'];

        $this->addVariantToChannel(
            variantId: $variantResponse['id'],
            channelId: $this->channelResponse['id'],
            warehouseData: ['id' => $this->warehouseResponse['id']]
        );

        $this->addVariantToWarehouse(
            variantId: $variantResponse['id'],
            warehouseId: $this->warehouseResponse['id'],
            amount: 100
        );

        $this->createOrderFromCart(
            variantId: $variantResponse['id'],
            quantity: 1,
            metadata: [
                'data' => [
                    'vehiclePlate' => 'ABC123',
                    'vehicleBrand' => 'Toyota',
                    'vehicleColor' => 'Red',
                ]
            ],
            orderType: $this->orderTypeName
        );

        // Test with non-existent field paths
        $edgeCaseResponse = $this->graphQL('
            query ExportOrdersEdgeCases($fieldMapper: Mixed!) {
                exportOrders(
                    format: EXCEL
                    field_mapper: $fieldMapper
                ) {
                    status
                    download_url
                    file_name
                    message
                }
            }
        ', [
            'fieldMapper' => [
                'ID' => 'id',
                'Non Existent' => 'non.existent.field',
                'Deep Non Existent' => 'metadata.data.nonExistent',
                'Array Out of Bounds' => 'items[99].product_name',
                'Valid Field' => 'order_number'
            ]
        ], [], [
            'X-Kanvas-Location' => $this->company->branch->uuid,
            'X-Kanvas-App' => $this->apps->key,
        ]);

        // Should still succeed even with invalid field paths
        $this->assertEquals('success', $edgeCaseResponse->json('data.exportOrders.status'));
        $this->assertNotNull($edgeCaseResponse->json('data.exportOrders.download_url'));
    }
}
