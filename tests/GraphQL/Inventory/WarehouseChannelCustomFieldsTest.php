<?php

declare(strict_types=1);

namespace Tests\GraphQL\Inventory;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Inventory\Channels\Models\Channels;
use Kanvas\Inventory\Regions\Models\Regions;
use Kanvas\Inventory\Support\Setup as InventorySetup;
use Kanvas\Inventory\Warehouses\Actions\CreateWarehouseAction;
use Kanvas\Inventory\Warehouses\DataTransferObject\Warehouses as WarehousesDto;
use Kanvas\Inventory\Warehouses\Models\Warehouses;
use Kanvas\Users\Models\Users;
use Tests\GraphQL\Inventory\Traits\InventoryCases;
use Tests\TestCase;

/**
 * `custom_fields` on Warehouse and Channel is resolver-backed rather than a relation passthrough,
 * so it can compile fine and still return nothing — the resolver reads the model's Redis cache and
 * silently yields an empty page if the model can't answer. These drive a real query.
 *
 * This is the read path the frontend uses to show a warehouse's NetSuite location mapping.
 */
class WarehouseChannelCustomFieldsTest extends TestCase
{
    use DatabaseTransactions;
    use InventoryCases;

    protected array $connectionsToTransact = ['mysql', 'ecosystem', 'inventory'];

    public function testWarehouseExposesItsCustomFields(): void
    {
        $warehouse = $this->createWarehouse();
        $warehouse->set('NET_SUITE_LOCATION_ID', '7');

        $response = $this->graphQL('
            query ($id: Mixed!) {
                warehouses(where: { column: ID, operator: EQ, value: $id }) {
                    data {
                        id
                        name
                        custom_fields(first: 25) {
                            data { name value }
                        }
                    }
                }
            }
        ', ['id' => $warehouse->getId()]);

        $response->assertSuccessful();

        $fields = $response->json('data.warehouses.data.0.custom_fields.data');

        $this->assertNotNull($fields, 'custom_fields did not resolve on Warehouse');
        $this->assertContains(
            'NET_SUITE_LOCATION_ID',
            array_column($fields, 'name')
        );
        $this->assertSame(
            '7',
            (string) array_column($fields, 'value', 'name')['NET_SUITE_LOCATION_ID']
        );
    }

    public function testChannelExposesItsCustomFields(): void
    {
        $id = $this->graphQL('
            mutation ($data: CreateChannelInput!) {
                createChannel(input: $data) { id }
            }
        ', ['data' => ['name' => 'CF Channel ' . uniqid(), 'is_default' => false]])
            ->assertSuccessful()
            ->json('data.createChannel.id');

        /** @var Channels $channel */
        $channel = Channels::getById((int) $id, app(Apps::class));
        $channel->set('EXTERNAL_CHANNEL_ID', 'abc-123');

        $response = $this->graphQL('
            query ($id: Mixed!) {
                channels(where: { column: ID, operator: EQ, value: $id }) {
                    data {
                        id
                        custom_fields(first: 25) {
                            data { name value }
                        }
                    }
                }
            }
        ', ['id' => $id]);

        $response->assertSuccessful();

        $fields = $response->json('data.channels.data.0.custom_fields.data');

        $this->assertNotNull($fields, 'custom_fields did not resolve on Channel');
        $this->assertSame(
            'abc-123',
            (string) array_column($fields, 'value', 'name')['EXTERNAL_CHANNEL_ID']
        );
    }

    private function createWarehouse(): Warehouses
    {
        $app = app(Apps::class);
        /** @var Users $user */
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        new InventorySetup($app, $user, $company)->run();

        /** @var Regions $region */
        $region = Regions::fromApp($app)->fromCompany($company)->firstOrFail();

        return new CreateWarehouseAction(
            new WarehousesDto(
                company: $company,
                app: $app,
                user: $user,
                region: $region,
                name: 'CF Warehouse ' . uniqid(),
            ),
            $user
        )->execute();
    }
}
