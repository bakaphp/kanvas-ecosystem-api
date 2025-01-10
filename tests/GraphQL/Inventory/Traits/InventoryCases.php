<?php

namespace Tests\GraphQL\Inventory\Traits;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Enums\StateEnums;
use Baka\Support\Str;
use Baka\Users\Contracts\UserInterface;
use Illuminate\Testing\TestResponse;
use Kanvas\Currencies\Models\Currencies;
use Kanvas\Inventory\Regions\Actions\CreateRegionAction;
use Kanvas\Inventory\Regions\DataTransferObject\Region;
use Kanvas\Inventory\Status\Actions\CreateStatusAction;
use Kanvas\Inventory\Status\DataTransferObject\Status;
use Kanvas\Inventory\Status\Models\Status as ModelsStatus;
use Kanvas\Inventory\Warehouses\Actions\CreateWarehouseAction;
use Kanvas\Inventory\Warehouses\DataTransferObject\Warehouses as DataTransferObjectWarehouses;
use Kanvas\Inventory\Warehouses\Models\Warehouses;
use Kanvas\Regions\Models\Regions;

trait InventoryCases
{
    public function createProduct(array $data = []): TestResponse
    {
        if (empty($data)) {
            $name = fake()->name;
            $data = [
                'name' => $name,
                'description' => fake()->text,
                'sku' => fake()->time,
                'slug' => Str::slug($name),
                'attributes' => [
                    [
                        'name' => fake()->name,
                        'value' => fake()->name,
                    ],
                ],
            ];
        }

        return $this->graphQL('
            mutation($data: ProductInput!) {
                createProduct(input: $data)
                {
                    id
                    name
                    description
                    slug
                    attributes {
                        name
                        value
                    }
                }
            }', ['data' => $data]);
    }

    public function createVariant(string $productId, array $warehouseData, array $data = []): TestResponse
    {
        if (empty($data)) {
            $data = [
                'name' => fake()->name,
                'description' => fake()->text,
                'products_id' => $productId,
                'sku' => fake()->time,
                'warehouses' => [$warehouseData],
                'attributes' => [
                    [
                        'name' => fake()->name,
                        'value' => fake()->name,
                    ],
                ],
            ];
        }

        return $this->graphQL('
            mutation($data: VariantsInput!) {
                createVariant(input: $data)
                {
                    id
                    name
                    sku
                    description
                    products_id
                }
            }', ['data' => $data]);
    }

    public function createRegion(array $data = []): TestResponse
    {
        if (empty($data)) {
            $data = [
                'name' => fake()->name,
                'slug' => Str::slug(fake()->name),
                'short_slug' =>  Str::slug(fake()->name),
                'is_default' => 1,
                'currency_id' => 1,
            ];
        }

        return $this->graphQL('
            mutation($data: RegionInput!) {
                createRegion(input: $data)
                {
                    id
                    name
                    slug
                    short_slug
                    currency_id
                    is_default
                }
            }
        ', ['data' => $data]);
    }

    public function createWarehouses(string $regionId, array $data = []): TestResponse
    {
        if (empty($data)) {
            $data = [
                'regions_id' => $regionId,
                'name' => fake()->name,
                'location' => 'Test Location',
                'is_default' => true,
                'is_published' => true,
            ];
        }

        return $this->graphQL('
            mutation($data: WarehouseInput!) {
                createWarehouse(input: $data)
                {
                    id
                    regions_id
                    name
                    location
                    is_default
                    is_published
                }
            }', ['data' => $data]);
    }

    public function createDefaultWarehouse(CompanyInterface $company, AppInterface $app, UserInterface $user, Regions $region): Warehouses
    {
        $createWarehouse = new CreateWarehouseAction(
            new DataTransferObjectWarehouses(
                $company,
                $app,
                $user,
                $region,
                StateEnums::DEFAULT_NAME->getValue(),
                StateEnums::DEFAULT_NAME->getValue(),
                (bool) StateEnums::YES->getValue(),
                (bool) StateEnums::YES->getValue(),
            ),
            $user
        );

        return $createWarehouse->execute();
    }

    public function createDefaultRegion(CompanyInterface $company, AppInterface $app, UserInterface $user): Regions
    {
        $createRegion = new CreateRegionAction(
            new Region(
                $company,
                $app,
                $user,
                Currencies::where('code', 'USD')->firstOrFail(),
                StateEnums::DEFAULT_NAME->getValue(),
                StateEnums::DEFAULT_NAME->getValue(),
                null,
                StateEnums::YES->getValue(),
            ),
            $user
        );

        return $createRegion->execute();
    }

    public function createDefaultStatus(CompanyInterface $company, AppInterface $app, UserInterface $user): ModelsStatus
    {
        $createDefaultStatus = new CreateStatusAction(
            new Status(
                $app,
                $company,
                $user,
                'Default',
                true
            ),
            $user
        );

        return $createDefaultStatus->execute();
    }
}
