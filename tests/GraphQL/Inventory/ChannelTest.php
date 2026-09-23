<?php

declare(strict_types=1);

namespace Tests\GraphQL\Inventory;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Inventory\Channels\Actions\UnPublishAllVariantsAction;
use Kanvas\Inventory\Channels\Models\Channels;
use Kanvas\Inventory\Variants\Models\Variants;
use Kanvas\Inventory\Variants\Models\VariantsChannels;
use Kanvas\Inventory\Warehouses\Models\Warehouses;
use Kanvas\Souk\Enums\ConfigurationEnum as SoukConfigurationEnum;
use Tests\GraphQL\Inventory\Traits\InventoryCases;
use Tests\TestCase;

class ChannelTest extends TestCase
{
    use InventoryCases;

    /**
     * testCreateChannel.
     */
    public function testCreateChannel(): void
    {
        $data = [
            'name' => fake()->name,
            'is_default' => true,
        ];
        $this->graphQL('
            mutation($data: CreateChannelInput!) {
                createChannel(input: $data)
                {
                    id
                    name,
                    is_default
                }
            }', ['data' => $data])->assertJson([
            'data' => ['createChannel' => $data],
        ]);
    }

    /**
     * testGetChannels.
     */
    public function testGetChannels(): void
    {
        $response = $this->graphQL('
            query {
                channels {
                    data {
                        id,
                        name,
                        is_default
                    }
                }
            }');

        $this->assertArrayHasKey('id', $response->json()['data']['channels']['data'][0]);
    }

    /**
     * testUpdateChannel.
     */
    public function testUpdateChannel(): void
    {
        $data = [
            'name' => fake()->name,
            'is_default' => true,
        ];
        $newChannel = $this->graphQL('
            mutation($data: CreateChannelInput!) {
                createChannel(input: $data)
                {
                    id
                    name,
                    is_default
                }
            }', ['data' => $data])->assertJson([
            'data' => ['createChannel' => $data],
        ]);
        $channelId = $newChannel['data']['createChannel']['id'];

        $this->graphQL('
        query($id: Mixed!) {
            channels(where: {column: ID, operator: EQ, value: $id}) {
                data {
                    id,
                    name,
                    is_default
                }
            }
        }', ['id' => $channelId])->assertJson([
            'data' => ['channels' => ['data' => [$data]]],
        ]);

        $data = [
            'name' => fake()->name,
        ];
        $this->graphQL('
            mutation($channelId: ID!, $data: UpdateChannelInput!) {
                updateChannel(id: $channelId, input: $data)
                {
                    name
                }
            }', ['channelId' => $channelId, 'data' => $data])->assertJson([
            'data' => ['updateChannel' => $data],
        ]);
    }

    /**
     * testDeleteChannel.
     */
    public function testDeleteChannel(): void
    {
        $data = [
            'name' => fake()->name,
            'is_default' => false,
        ];
        $newChannel = $this->graphQL('
            mutation($data: CreateChannelInput!) {
                createChannel(input: $data)
                {
                    id
                    name,
                    is_default
                }
            }', ['data' => $data])->assertJson([
            'data' => ['createChannel' => $data],
        ]);

        $channelId = $newChannel['data']['createChannel']['id'];

        $this->graphQL('
        query($id: Mixed!) {
            channels(where: {column: ID, operator: EQ, value: $id}) {
                data {
                    id,
                    name,
                    is_default
                }
            }
        }', ['id' => $channelId])->assertJson([
            'data' => ['channels' => ['data' => [$data]]],
        ]);

        $this->graphQL('
            mutation($id: ID!) {
                deleteChannel(id: $id)
            }', ['id' => $channelId])->assertJson([
            'data' => ['deleteChannel' => true],
        ]);
    }

    /**
     * testUnpublishProducts.
     */
    public function testUnpublishProductsFromChannel(): void
    {
        $data = [
            'name' => fake()->name,
            'is_default' => true,
        ];
        $newChannel = $this->graphQL('
            mutation($data: CreateChannelInput!) {
                createChannel(input: $data)
                {
                    id
                    name,
                    is_default
                }
            }', ['data' => $data])->assertJson([
            'data' => ['createChannel' => $data],
        ]);
        $channelId = $newChannel['data']['createChannel']['id'];
        $this->graphQL('
        query($id: Mixed!) {
            channels(where: {column: ID, operator: EQ, value: $id}) {
                data {
                    id,
                    name,
                    is_default
                }
            }
        }', ['id' => $channelId])->assertJson([
            'data' => ['channels' => ['data' => [$data]]],
        ]);

        $this->graphQL('
            mutation($id: ID!) {
                unPublishAllVariantsFromChannel(id: $id)
            }', ['id' => $channelId])->assertJson([
            'data' => ['unPublishAllVariantsFromChannel' => true], // job dispatched to queue
        ]);
    }

    public function testUnPublishAllVariantsAction(): void
    {
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        $app = app(Apps::class);

        $this->setupInventory($app, $company, $user);

        $warehouse = Warehouses::fromApp($app)->fromCompany($company)->first();
        $channel = Channels::fromApp($app)->fromCompany($company)->first();

        $productResponse = $this->createProduct();
        $productResponse->assertJsonMissingPath('errors.0');
        $productId = $productResponse->json('data.createProduct.id');
        $this->assertNotNull($productId);

        $variantResponse = $this->createVariant(
            (string) $productId,
            [
                'id' => $warehouse->getId(),
                'price' => 10.00,
                'quantity' => 5,
                'position' => 1,
            ]
        );
        $variantId = $variantResponse->json('data.createVariant.id');

        $this->addVariantToChannel(
            (string) $variantId,
            (string) $channel->getId(),
            ['id' => $warehouse->getId()]
        );

        // Verify it's published in the channel
        $channelRecord = VariantsChannels::where('channels_id', $channel->getId())
            ->where('is_published', 1)
            ->whereHas('variant', fn ($q) => $q->where('id', $variantId))
            ->first();
        $this->assertNotNull($channelRecord);

        // Run the action
        new UnPublishAllVariantsAction($channel)->execute();

        // Verify it's unpublished (re-query since composite PK doesn't support refresh)
        $updatedRecord = VariantsChannels::where('channels_id', $channel->getId())
            ->whereHas('variant', fn ($q) => $q->where('id', $variantId))
            ->first();
        $this->assertNotNull($updatedRecord);
        $this->assertEquals(0, (int) $updatedRecord->is_published);
    }

    public function testUnPublishAllVariantsActionKeepsTheSkusStillInTheFeed(): void
    {
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        $app = app(Apps::class);

        $this->setupInventory($app, $company, $user);

        $warehouse = Warehouses::fromApp($app)->fromCompany($company)->first();
        $channel = Channels::fromApp($app)->fromCompany($company)->first();

        $variantIds = [];
        foreach ([1, 2] as $ignored) {
            $productId = $this->createProduct()->json('data.createProduct.id');
            $variantId = $this->createVariant(
                (string) $productId,
                ['id' => $warehouse->getId(), 'price' => 10.00, 'quantity' => 1, 'position' => 1]
            )->json('data.createVariant.id');
            $this->addVariantToChannel((string) $variantId, (string) $channel->getId(), ['id' => $warehouse->getId()]);
            $variantIds[] = (int) $variantId;
        }

        [$stillInFeed, $sold] = $variantIds;

        new UnPublishAllVariantsAction($channel, [Variants::getById($stillInFeed)->sku])->execute();

        $published = fn (int $variantId) => (int) VariantsChannels::where('channels_id', $channel->getId())
            ->where('products_variants_id', $variantId)
            ->value('is_published');

        $this->assertSame(1, $published($stillInFeed));
        $this->assertSame(0, $published($sold));
    }

    public function testTypesenseSchemaIdIsString(): void
    {
        $schema = new Channels()->typesenseCollectionSchema();
        $idField = collect($schema['fields'])->firstWhere('name', 'id');

        $this->assertNotNull($idField);
        $this->assertSame('string', $idField['type'], 'Typesense requires the document id field to be a string');
    }

    /**
     * ChannelObserver::creating() would fatal resolving `$channel->company` (null for
     * companies_id = 0), so the global row is seeded via a raw insert — the same way it would
     * actually be provisioned in production (a one-time seed, not through createChannel).
     */
    private function createGlobalChannel(): int
    {
        $name = 'Global Channel ' . fake()->unique()->word();

        return DB::connection('inventory')->table('channels')->insertGetId([
            'users_id' => auth()->user()->getId(),
            'companies_id' => 0,
            'apps_id' => app(Apps::class)->getId(),
            'uuid' => (string) Str::uuid(),
            'name' => $name,
            'slug' => Str::slug($name),
            'is_published' => 1,
            'is_deleted' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function testChannelsQueryIncludesGlobalChannelRegardlessOfCrossCompanyFlag(): void
    {
        $app = app(Apps::class);
        $originalFlag = $app->get(SoukConfigurationEnum::ALLOW_CROSS_COMPANY_VARIANTS->value);
        // Channels overrides scopeFromCompanyOrGlobal() specifically to NOT depend on this flag —
        // it's off here on purpose, proving the global channel shows up either way.
        $app->set(SoukConfigurationEnum::ALLOW_CROSS_COMPANY_VARIANTS->value, 0);

        try {
            $globalId = $this->createGlobalChannel();

            // `companies` must be nullable on the Channel type — a companies_id = 0 row has no
            // owning company, and selecting the relation used to fatal with "Cannot return null
            // for non-nullable field Channel.companies".
            $this->graphQL('
                query($id: Mixed!) {
                    channels(where: {column: ID, operator: EQ, value: $id}) {
                        data { id companies_id companies { id } }
                    }
                }
            ', ['id' => $globalId])->assertJson([
                'data' => ['channels' => ['data' => [[
                    'id' => (string) $globalId,
                    'companies_id' => 0,
                    'companies' => null,
                ]]]],
            ]);
        } finally {
            $app->set(SoukConfigurationEnum::ALLOW_CROSS_COMPANY_VARIANTS->value, $originalFlag);
        }
    }

    public function testUnPublishAllVariantsActionDoesNotFatalOnAGlobalChannel(): void
    {
        $globalId = $this->createGlobalChannel();

        // Used to fatal on `$this->channel->company->get(...)` — a companies_id = 0 channel has
        // no owning company to read the "don't unpublish" setting from.
        new UnPublishAllVariantsAction(Channels::findOrFail($globalId))->execute();

        $this->addToAssertionCount(1);
    }

    public function testChannelsQueryReturnsBothTheCompanyChannelAndTheGlobalOneTogether(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $globalId = $this->createGlobalChannel();
        $companyChannel = Channels::create([
            'users_id' => $user->getId(),
            'companies_id' => $company->getId(),
            'apps_id' => $app->getId(),
            'name' => 'Company Channel ' . fake()->unique()->word(),
            'is_default' => 1,
            'is_published' => 1,
            'is_deleted' => 0,
        ]);

        $response = $this->graphQL('
            query($ids: Mixed!) {
                channels(where: {column: ID, operator: IN, value: $ids}) {
                    data { id companies_id }
                }
            }
        ', ['ids' => [$globalId, $companyChannel->getId()]])->assertSuccessful();

        $returnedIds = collect($response->json('data.channels.data'))->pluck('id')->all();

        $this->assertContains((string) $globalId, $returnedIds);
        $this->assertContains((string) $companyChannel->getId(), $returnedIds);
        $this->assertCount(2, $returnedIds);
    }

    public function testChannelsQueryStillScopesToOwnCompanyForNonGlobalChannels(): void
    {
        $app = app(Apps::class);
        $otherCompanyChannel = Channels::create([
            'users_id' => auth()->user()->getId(),
            'companies_id' => Companies::factory()->create()->getId(),
            'apps_id' => $app->getId(),
            'name' => 'Other Company Channel ' . fake()->unique()->word(),
            'is_default' => 1,
            'is_published' => 1,
            'is_deleted' => 0,
        ]);

        $this->graphQL('
            query($id: Mixed!) {
                channels(where: {column: ID, operator: EQ, value: $id}) {
                    data { id }
                }
            }
        ', ['id' => $otherCompanyChannel->getId()])->assertJson([
            'data' => ['channels' => ['data' => []]],
        ]);
    }

    public function testChannelsQueryDefaultsToAscendingIdOrder(): void
    {
        $ids = $this->createCompanyChannelIds(3);

        $response = $this->graphQL('
            query($ids: Mixed!) {
                channels(where: {column: ID, operator: IN, value: $ids}) {
                    data { id }
                }
            }
        ', ['ids' => $ids])->assertSuccessful();

        $this->assertSame(
            array_map('strval', $ids),
            collect($response->json('data.channels.data'))->pluck('id')->all()
        );
    }

    /**
     * MySQL usually hands rows back in PK order anyway, so the GraphQL assertion above can pass
     * without the scope — this pins the ORDER BY clause itself.
     */
    public function testDefaultOrderScopeOnlyOrdersWhenNoOrderByArgument(): void
    {
        $this->assertSame(
            [['column' => 'channels.id', 'direction' => 'asc']],
            Channels::query()->defaultOrder([])->getQuery()->orders
        );

        $this->assertEmpty(
            Channels::query()->defaultOrder(['orderBy' => [['column' => 'name', 'order' => 'DESC']]])->getQuery()->orders
        );
    }

    public function testChannelsQueryExplicitOrderByOverridesDefaultOrder(): void
    {
        $ids = $this->createCompanyChannelIds(3);

        $response = $this->graphQL('
            query($ids: Mixed!) {
                channels(
                    where: {column: ID, operator: IN, value: $ids}
                    orderBy: [{column: ID, order: DESC}]
                ) {
                    data { id }
                }
            }
        ', ['ids' => $ids])->assertSuccessful();

        $this->assertSame(
            array_map('strval', array_reverse($ids)),
            collect($response->json('data.channels.data'))->pluck('id')->all()
        );
    }

    /**
     * @return int[] ascending
     */
    private function createCompanyChannelIds(int $count): array
    {
        $user = auth()->user();

        return collect(range(1, $count))
            ->map(fn () => Channels::create([
                'users_id' => $user->getId(),
                'companies_id' => $user->getCurrentCompany()->getId(),
                'apps_id' => app(Apps::class)->getId(),
                'name' => 'Ordered Channel ' . fake()->unique()->word(),
                'is_default' => 0,
                'is_published' => 1,
                'is_deleted' => 0,
            ])->getId())
            ->all();
    }

    public function testChannelRegionsResolvesFromVariantChannels(): void
    {
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        $app = app(Apps::class);

        $this->setupInventory($app, $company, $user);

        $warehouse = Warehouses::fromApp($app)->fromCompany($company)->firstOrFail();
        $channel = Channels::fromApp($app)->fromCompany($company)->firstOrFail();

        $productResponse = $this->createProduct();
        $productId = $productResponse->json('data.createProduct.id');

        $variantResponse = $this->createVariant(
            (string) $productId,
            [
                'id' => $warehouse->getId(),
                'price' => 10.00,
                'quantity' => 5,
                'position' => 1,
            ]
        );
        $variantResponse->assertJsonMissingPath('errors.0');
        $variantId = $variantResponse->json('data.createVariant.id');
        $this->assertNotNull($variantId);

        $addToChannelResponse = $this->addVariantToChannel(
            (string) $variantId,
            (string) $channel->getId(),
            ['id' => $warehouse->getId()]
        );
        $addToChannelResponse->assertJsonMissingPath('errors.0');

        $regions = $channel->fresh()->getRegions();

        $this->assertNotNull($regions);
        $this->assertTrue($regions->isNotEmpty());
        $this->assertNotNull($regions->first()?->id);
    }
}
