<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\NetSuite;

use Exception;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\NetSuite\Actions\SyncAllNetSuiteCustomerItemsListAction;
use Kanvas\Connectors\NetSuite\Enums\CustomFieldEnum;
use Kanvas\Connectors\NetSuite\Services\NetSuiteCustomerService;
use Kanvas\Connectors\NetSuite\Services\NetSuiteProductSearchService;
use Kanvas\Inventory\Channels\Models\Channels;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Inventory\Variants\Models\VariantsChannels;
use Kanvas\Inventory\Variants\Models\VariantsWarehouses;
use Kanvas\Inventory\Warehouses\Models\Warehouses;
use Mockery;
use NetSuite\Classes\Customer;
use SoapFault;
use stdClass;
use Tests\TestCase;

final class SyncAllNetSuiteCustomerItemsListActionTest extends TestCase
{
    use DatabaseTransactions;

    protected Apps $apps;
    protected Companies $mainCompany;

    protected function setUp(): void
    {
        parent::setUp();

        $this->apps = app(Apps::class);
        $this->mainCompany = Companies::first();
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    protected function bindMockProductSearch(int $productCount): void
    {
        $products = [];
        for ($i = 1; $i <= $productCount; $i++) {
            $products[] = [
                'internalId' => (string) $i,
                'itemId' => "ITEM-{$i}",
                'displayName' => "Product {$i}",
                'quantityAvailable' => 10,
                'basePrice' => 9.99,
            ];
        }

        $mockSearch = Mockery::mock(NetSuiteProductSearchService::class);
        $mockSearch->shouldReceive('searchWithSavedSearch')
            ->andReturn($products);

        $this->app->bind(
            NetSuiteProductSearchService::class,
            fn () => $mockSearch
        );
    }

    protected function bindMockCustomerService(array $barcodes): void
    {
        $itemPricing = array_map(function (string $barcode) {
            $item = new stdClass();
            $item->name = $barcode;

            $pricing = new stdClass();
            $pricing->item = $item;
            $pricing->price = 9.99;

            return $pricing;
        }, $barcodes);

        $itemPricingList = new stdClass();
        $itemPricingList->itemPricing = $itemPricing;

        $customer = new Customer();
        $customer->itemPricingList = $itemPricingList;

        $mockService = Mockery::mock(NetSuiteCustomerService::class);
        $mockService->shouldReceive('getCustomerById')
            ->andReturn($customer);

        $this->app->bind(
            NetSuiteCustomerService::class,
            fn () => $mockService
        );
    }

    protected function createBuyerWithNetSuiteId(string $netSuiteId = 'NS-TEST-123'): Companies
    {
        $buyer = Companies::factory()->create([
            'users_id' => auth()->id(),
        ]);
        $buyer->set(CustomFieldEnum::NET_SUITE_CUSTOMER_ID->value, $netSuiteId);

        return $buyer;
    }

    protected function createChannelForBuyer(Companies $buyer): Channels
    {
        return Channels::create([
            'companies_id' => $this->mainCompany->getId(),
            'apps_id' => $this->apps->getId(),
            'users_id' => auth()->id(),
            'name' => $buyer->name . ' Channel',
            'slug' => (string) $buyer->uuid,
            'description' => 'test',
            'is_published' => 0,
            'is_deleted' => 0,
        ]);
    }

    protected function addVariantsToChannel(Channels $channel, int $count): void
    {
        $warehouse = Warehouses::where('companies_id', $this->mainCompany->getId())
            ->where('apps_id', $this->apps->getId())
            ->first();

        for ($i = 0; $i < $count; $i++) {
            $product = Products::factory()
                ->withCompanyId($this->mainCompany->getId())
                ->create();

            $variant = $product->variants()->firstOrFail();

            $variantWarehouse = VariantsWarehouses::firstOrCreate([
                'products_variants_id' => $variant->getId(),
                'warehouses_id' => $warehouse->getId(),
            ], [
                'price' => 10.00,
                'quantity' => 1,
                'is_deleted' => 0,
            ]);

            VariantsChannels::create([
                'product_variants_warehouse_id' => $variantWarehouse->id,
                'products_variants_id' => $variant->getId(),
                'channels_id' => $channel->getId(),
                'warehouses_id' => $warehouse->getId(),
                'price' => 10.00,
                'discounted_price' => 10.00,
                'is_published' => 1,
                'is_deleted' => 0,
            ]);
        }
    }

    public function testSkipsWhenMainCompanyIdMissing(): void
    {
        $this->apps->del('B2B_MAIN_COMPANY_ID');

        $this->expectException(Exception::class);
        $this->expectExceptionMessageMatches('/B2B_MAIN_COMPANY_ID/');

        new SyncAllNetSuiteCustomerItemsListAction($this->apps)->execute();
    }

    public function testSkipsBuyersWithoutNetSuiteCustomerId(): void
    {
        $this->apps->set('B2B_MAIN_COMPANY_ID', $this->mainCompany->getId());
        $this->bindMockProductSearch(5);

        $result = new SyncAllNetSuiteCustomerItemsListAction(
            $this->apps,
            onlyBuyerIds: [-99999]
        )->execute();

        $this->assertEquals(0, $result['total_buyers']);
        $this->assertEmpty($result['results']);
    }

    public function testMarksSyncWhenChannelMissing(): void
    {
        $this->apps->set('B2B_MAIN_COMPANY_ID', $this->mainCompany->getId());
        $this->bindMockProductSearch(5);
        $this->bindMockCustomerService(['BARCODE-1', 'BARCODE-2']);

        $buyer = $this->createBuyerWithNetSuiteId('NS-NO-CHANNEL');

        $result = new SyncAllNetSuiteCustomerItemsListAction(
            $this->apps,
            dryRun: false,
            onlyBuyerIds: [$buyer->getId()]
        )->execute();

        $buyerResult = $result['results'][0];
        $this->assertFalse($buyerResult['channel_found']);
        $this->assertTrue($buyerResult['synced']);
        $this->assertNull($buyerResult['error']);
        $this->assertEquals(5, $buyerResult['product_count']);
    }

    public function testNoSyncWhenCountsMatch(): void
    {
        $this->apps->set('B2B_MAIN_COMPANY_ID', $this->mainCompany->getId());
        $this->bindMockProductSearch(3);

        $buyer = $this->createBuyerWithNetSuiteId('NS-MATCH');
        $channel = $this->createChannelForBuyer($buyer);
        $this->addVariantsToChannel($channel, 3);

        $result = new SyncAllNetSuiteCustomerItemsListAction(
            $this->apps,
            onlyBuyerIds: [$buyer->getId()]
        )->execute();

        $buyerResult = $result['results'][0];
        $this->assertFalse($buyerResult['synced']);
        $this->assertEquals(0, $buyerResult['missing_count']);
        $this->assertEquals(1, $result['total_skipped']);
    }

    public function testSyncsWhenChannelHasFewerProducts(): void
    {
        $this->apps->set('B2B_MAIN_COMPANY_ID', $this->mainCompany->getId());
        $this->bindMockProductSearch(5);
        $this->bindMockCustomerService(['BARCODE-1']);

        $buyer = $this->createBuyerWithNetSuiteId('NS-PARTIAL');
        $channel = $this->createChannelForBuyer($buyer);
        $this->addVariantsToChannel($channel, 2);

        $result = new SyncAllNetSuiteCustomerItemsListAction(
            $this->apps,
            dryRun: false,
            onlyBuyerIds: [$buyer->getId()]
        )->execute();

        $buyerResult = $result['results'][0];
        $this->assertTrue($buyerResult['synced']);
        $this->assertEquals(3, $buyerResult['missing_count']);
    }

    public function testDryRunDoesNotCallSync(): void
    {
        $this->apps->set('B2B_MAIN_COMPANY_ID', $this->mainCompany->getId());
        $this->bindMockProductSearch(5);

        $buyer = $this->createBuyerWithNetSuiteId('NS-DRYRUN');

        $result = new SyncAllNetSuiteCustomerItemsListAction(
            $this->apps,
            dryRun: true,
            onlyBuyerIds: [$buyer->getId()]
        )->execute();

        $buyerResult = $result['results'][0];
        $this->assertFalse($buyerResult['synced']);
        $this->assertTrue($result['dry_run']);

        $channelExists = Channels::where('slug', (string) $buyer->uuid)
            ->where('apps_id', $this->apps->getId())
            ->exists();
        $this->assertFalse($channelExists);
    }

    public function testSoapFaultOnSyncDoesNotAbortSweep(): void
    {
        $this->apps->set('B2B_MAIN_COMPANY_ID', $this->mainCompany->getId());
        $this->bindMockProductSearch(5);

        $buyerA = $this->createBuyerWithNetSuiteId('NS-FAULT-A');
        $buyerB = $this->createBuyerWithNetSuiteId('NS-FAULT-B');

        $callCount = 0;
        $mockService = Mockery::mock(NetSuiteCustomerService::class);
        $mockService->shouldReceive('getCustomerById')
            ->andReturnUsing(function () use (&$callCount) {
                $callCount++;
                if ($callCount === 1) {
                    throw new SoapFault('Server', 'concurrent request limit exceeded');
                }

                $item = new stdClass();
                $item->name = 'OK-001';
                $pricing = new stdClass();
                $pricing->item = $item;
                $pricing->price = 9.99;
                $list = new stdClass();
                $list->itemPricing = [$pricing];
                $customer = new Customer();
                $customer->itemPricingList = $list;

                return $customer;
            });

        $this->app->bind(
            NetSuiteCustomerService::class,
            fn () => $mockService
        );

        $result = new SyncAllNetSuiteCustomerItemsListAction(
            $this->apps,
            onlyBuyerIds: [$buyerA->getId(), $buyerB->getId()]
        )->execute();

        $this->assertEquals(2, $result['total_buyers']);
        $this->assertGreaterThanOrEqual(1, $result['total_errors']);

        $resultByCompany = collect($result['results'])->keyBy('company_id');

        $resultA = $resultByCompany[$buyerA->getId()];
        $this->assertNotNull($resultA['error']);

        $resultB = $resultByCompany[$buyerB->getId()];
        $this->assertNull($resultB['error']);
    }

    public function testExcludesMainCompanyFromBuyerList(): void
    {
        $this->apps->set('B2B_MAIN_COMPANY_ID', $this->mainCompany->getId());
        $this->bindMockProductSearch(5);
        $this->mainCompany->set(CustomFieldEnum::NET_SUITE_CUSTOMER_ID->value, 'NS-MAIN-CO');

        $result = new SyncAllNetSuiteCustomerItemsListAction(
            $this->apps,
            onlyBuyerIds: [$this->mainCompany->getId()]
        )->execute();

        $companyIds = array_column($result['results'], 'company_id');
        $this->assertNotContains($this->mainCompany->getId(), $companyIds);
    }
}
