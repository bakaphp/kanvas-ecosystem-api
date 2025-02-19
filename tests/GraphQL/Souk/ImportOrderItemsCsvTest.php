<?php

declare(strict_types=1);

namespace Tests\GraphQL\Souk;

use Illuminate\Http\UploadedFile;
use Tests\GraphQL\Inventory\Traits\InventoryCases;
use Tests\TestCase;

class ImportOrderItemsCsvTest extends TestCase
{
    use InventoryCases;

    public function testImportOrderWithoutValidItemsCsv(): void
    {
        $operations = [
            'query' =>
            /** @lang GraphQL */
            '
                mutation ImportOrderCsv($file: Upload!, $channel_id: ID!) {
                    importOrderCsv(input: {file: $file, channel_id: $channel_id})
                    { 
                        status, 
                        message 
                    } 
                }
            ',
            'variables' => [
                'file' => null,
                'channel_id' => 1,
            ],
        ];

        $map = [
            '0' => ['variables.file']
        ];

        $file = [
            '0' => UploadedFile::fake()->createWithContent('products.csv', $this->getProductsCsvContent()),
        ];

        $response = $this->multipartGraphQL($operations, $map, $file);
        $response->assertJson([
            "data" => [
                "importOrderCsv" => [
                    "message" => "No valid order items found",
                    "status" => "error"
                ]
            ]
        ]);
    }


    public function testImportOrderItemsCsv(): void
    {
        $regionResponse = $this->createRegion()->json()['data']['createRegion'];
        $warehouseResponse = $this->createWarehouses($regionResponse['id'])->json()['data']['createWarehouse'];
        $productResponse = $this->createProduct()->json()['data']['createProduct'];

        $warehouseData = [
            'id' => $warehouseResponse['id'],
        ];

        $variantResponse = $this->createVariant(
            productId: $productResponse['id'],
            warehouseData: $warehouseData
        )->json()['data']['createVariant'];

        $variantResponse2 = $this->createVariant(
            productId: $productResponse['id'],
            warehouseData: $warehouseData
        )->json()['data']['createVariant'];

        $channelResponse = $this->createChannel()->json()['data']['createChannel'];

        $this->addVariantToChannel(
            variantId: $variantResponse['id'],
            channelId: $channelResponse['id'],
            warehouseData: $warehouseData
        );

        $this->addVariantToChannel(
            variantId: $variantResponse2['id'],
            channelId: $channelResponse['id'],
            warehouseData: $warehouseData
        );

        $this->addVariantToWarehouse(
            variantId: $variantResponse['id'],
            warehouseId: $warehouseResponse['id'],
            amount: 10
        );

        $this->addVariantToWarehouse(
            variantId: $variantResponse2['id'],
            warehouseId: $warehouseResponse['id'],
            amount: 10
        );

        $operations = [
            'query' =>
            /** @lang GraphQL */
            '
                mutation ImportOrderCsv($file: Upload!, $channel_id: ID!) {
                    importOrderCsv(input: {file: $file, channel_id: $channel_id})
                    { 
                        status, 
                        message 
                    } 
                }
            ',
            'variables' => [
                'file' => null,
                'channel_id' => $channelResponse['id'],
            ],
        ];

        $map = [
            '0' => ['variables.file']
        ];

        $csv = $this->getValidProductsCsvContent([
            [
                'id' => $variantResponse['id'],
                'name' => $variantResponse['name'],
                'sku' => $variantResponse['sku'],
            ],
            [
                'id' => $variantResponse2['id'],
                'name' => $variantResponse2['name'],
                'sku' => $variantResponse2['sku'],
            ],
        ], 5);

        $file = [
            '0' => UploadedFile::fake()->createWithContent('products.csv', $csv),
        ];

        $response = $this->multipartGraphQL($operations, $map, $file);
        $response->assertJson([
            "data" => [
                "importOrderCsv" => [
                    "message" => "Items processed successfully",
                    "status" => "success"
                ]
            ]
        ]);
    }

    public function testImportOrderItemsWithoutAvailableStock(): void
    {
        $regionResponse = $this->createRegion()->json()['data']['createRegion'];
        $warehouseResponse = $this->createWarehouses($regionResponse['id'])->json()['data']['createWarehouse'];
        $productResponse = $this->createProduct()->json()['data']['createProduct'];

        $warehouseData = [
            'id' => $warehouseResponse['id'],
        ];

        $variantResponse = $this->createVariant(
            productId: $productResponse['id'],
            warehouseData: $warehouseData
        )->json()['data']['createVariant'];

        $variantResponse2 = $this->createVariant(
            productId: $productResponse['id'],
            warehouseData: $warehouseData
        )->json()['data']['createVariant'];

        $channelResponse = $this->createChannel()->json()['data']['createChannel'];

        $this->addVariantToChannel(
            variantId: $variantResponse['id'],
            channelId: $channelResponse['id'],
            warehouseData: $warehouseData
        );

        $this->addVariantToChannel(
            variantId: $variantResponse2['id'],
            channelId: $channelResponse['id'],
            warehouseData: $warehouseData
        );

        $this->addVariantToWarehouse(
            variantId: $variantResponse['id'],
            warehouseId: $warehouseResponse['id'],
            amount: 2
        );

        $this->addVariantToWarehouse(
            variantId: $variantResponse2['id'],
            warehouseId: $warehouseResponse['id'],
            amount: 2
        );

        $operations = [
            'query' =>
            /** @lang GraphQL */
            '
                mutation ImportOrderCsv($file: Upload!, $channel_id: ID!) {
                    importOrderCsv(input: {file: $file, channel_id: $channel_id})
                    { 
                        status, 
                        message 
                    } 
                }
            ',
            'variables' => [
                'file' => null,
                'channel_id' => $channelResponse['id'],
            ],
        ];

        $map = [
            '0' => ['variables.file']
        ];

        $csv = $this->getValidProductsCsvContent([
            [
                'id' => $variantResponse['id'],
                'name' => $variantResponse['name'],
                'sku' => $variantResponse['sku'],
            ],
            [
                'id' => $variantResponse2['id'],
                'name' => $variantResponse2['name'],
                'sku' => $variantResponse2['sku'],
            ],
        ], 5);

        $file = [
            '0' => UploadedFile::fake()->createWithContent('products.csv', $csv),
        ];

        $response = $this->multipartGraphQL($operations, $map, $file);
        $response->assertJson([
            "data" => [
                "importOrderCsv" => [
                    "message" => "Not enough stock for product {$variantResponse['name']}",
                    "status" => "error"
                ]
            ]
        ]);
    }

    private function getProductsCsvContent($qty = 0): string
    {
        return '"Instructions: Please fill out the Quantity fields. Ensure all entries are accurate before uploading. Save the file as a CSV format."
"Variant ID",Name,"Copic Item No/ UPC",Order Qty,"Min Quantity",Price,Tax,Discount,Currency
1,"Dodge Durango 2020 WDEH75",WDEH75,' . $qty . ',8,40421,0,0,USD
23192,"Dodge Durango 2020 WDEH76",WDEH76,0,0,0,0,0,USD
2,"Ram 2500 2021 DJ7H92",DJ7H92,' . $qty . ',8,43674,0,0,USD
3,"Volkswagen Tiguan 2021 BW23VJ",BW23VJ,0,8,31125,0,0,USD
4,"Jeep Wrangler 2021 JLJS74",JLJS74,0,0,42699,0,0,USD
5,"Cadillac XT5 2022 6NH26",6NH26,0,0,47800,0,0,USD
6,"Ram 1500 2022 DT6X98",DT6X98,0,0,52989,0,0,USD
7,"Ford F-150 2022 W1E",W1E,0,0,60885,0,0,USD';
    }

    private function getValidProductsCsvContent(array $products, $qty = 0): string
    {
        return '"Instructions: Please fill out the Quantity fields. Ensure all entries are accurate before uploading. Save the file as a CSV format."' . "\n" .
            '"Variant ID",Name,"Copic Item No/ UPC",Order Qty,"Min Quantity",Price,Tax,Discount,Currency' . "\n" .
            collect($products)->map(
                fn ($product) =>
                "{$product['id']},\"{$product['name']}\",\"{$product['sku']}\",{$qty},0,0,0,0,0,USD"
            )->join("\n");
    }
}
