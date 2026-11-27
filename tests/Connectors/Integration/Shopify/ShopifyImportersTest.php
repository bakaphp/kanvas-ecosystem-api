<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Shopify;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Shopify\Enums\CustomFieldEnum;
use Kanvas\Connectors\Shopify\Handlers\ShopifyHandler;
use Kanvas\Connectors\Shopify\Importers\ShopifyCustomersImporter;
use Kanvas\Connectors\Shopify\Importers\ShopifyProductsImporter;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Inventory\Regions\Models\Regions;
use Kanvas\Inventory\Support\Setup as InventorySetup;
use Kanvas\Inventory\Variants\Models\Variants;
use Kanvas\Inventory\Warehouses\Models\Warehouses;
use Kanvas\Users\Models\Users;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\Integrations\Imports\Enums\IntegrationImportStatusEnum;
use Kanvas\Workflow\Integrations\Imports\Importers\IntegrationImporter;
use Kanvas\Workflow\Integrations\Imports\Jobs\RunIntegrationImportJob;
use Kanvas\Workflow\Integrations\Imports\Models\IntegrationImport;
use Kanvas\Workflow\Integrations\Models\IntegrationsCompany;
use Kanvas\Workflow\Integrations\Models\Status;
use Kanvas\Workflow\Models\Integrations;
use Override;
use RuntimeException;
use Tests\Connectors\Traits\HasThrowawayCompany;
use Tests\TestCase;

final class ShopifyImportersTest extends TestCase
{
    use DatabaseTransactions;
    use HasThrowawayCompany;

    /** Not inventory: CreateProductAction retries its own transaction there, which a savepoint would break. */
    protected array $connectionsToTransact = ['mysql', 'ecosystem', 'crm', 'social', 'workflow'];

    private Apps $kanvasApp;
    private Users $kanvasUser;
    private Companies $company;
    private Regions $region;
    private IntegrationsCompany $integrationCompany;

    protected function setUp(): void
    {
        parent::setUp();

        $this->kanvasApp = app(Apps::class);
        /** @var Users $user */
        $user = auth()->user();
        $this->kanvasUser = $user;
        $this->company = $this->freshCompany();

        new InventorySetup($this->kanvasApp, $this->kanvasUser, $this->company)->run();

        $this->region = Regions::fromApp($this->kanvasApp)->fromCompany($this->company)->where('is_default', 1)->firstOrFail();
        $this->integrationCompany = IntegrationsCompany::create([
            'apps_id' => $this->kanvasApp->getId(),
            'companies_id' => $this->company->getId(),
            'integrations_id' => $this->shopifyIntegration()->getId(),
            'region_id' => $this->region->getId(),
            'status_id' => Status::where('slug', 'active')->where('apps_id', 0)->firstOrFail()->getId(),
            'is_active' => true,
        ]);
    }

    public function testTheHandlerOffersProductsThenCustomers(): void
    {
        $importables = $this->integrationCompany->importables();

        $this->assertSame(['products', 'customers'], array_column($importables, 'entity'));
        $this->assertSame(['Productos', 'Clientes'], array_column($importables, 'label'));
        $this->assertInstanceOf(
            ShopifyProductsImporter::class,
            $this->integrationCompany->importerFor('products', $this->kanvasUser)
        );
        $this->assertInstanceOf(
            ShopifyCustomersImporter::class,
            $this->integrationCompany->importerFor('customers', $this->kanvasUser)
        );
    }

    public function testProductsLandInTheRegionsDefaultWarehouse(): void
    {
        $sku = 'SHOP-' . uniqid();

        $run = $this->runProducts([1001 => $this->shopifyProduct(1001, $sku, '25.00')]);

        $variant = Variants::fromCompany($this->company)->where('sku', $sku)->firstOrFail();
        $default = Warehouses::fromCompany($this->company)->where('is_default', 1)->where('regions_id', $this->region->getId())->firstOrFail();
        $row = $variant->variantWarehouses()->where('warehouses_id', $default->getId())->firstOrFail();

        $this->assertSame(IntegrationImportStatusEnum::COMPLETED, $run->status);
        $this->assertSame(1, $run->created);
        $this->assertSame(0, $run->failed);
        $this->assertSame('Shopify Shirt', $variant->product->name);
        $this->assertSame(25.0, (float) $row->price);
    }

    public function testImportingAgainUpdatesInsteadOfDuplicating(): void
    {
        $sku = 'SHOP-' . uniqid();
        $this->runProducts([1001 => $this->shopifyProduct(1001, $sku, '25.00')]);

        $run = $this->runProducts([1001 => $this->shopifyProduct(1001, $sku, '30.00')]);

        $this->assertSame(0, $run->created);
        $this->assertSame(1, $run->updated);
        $this->assertSame(1, Variants::fromCompany($this->company)->where('sku', $sku)->count());
    }

    public function testAProductShopifyFailsToReturnFailsAloneAndTheRestContinue(): void
    {
        $sku = 'SHOP-' . uniqid();

        $run = $this->runProducts([
            1001 => new RuntimeException('Shopify returned 404'),
            1002 => $this->shopifyProduct(1002, $sku, '10.00'),
        ]);

        $this->assertSame(1, $run->created);
        $this->assertSame(1, $run->failed);
        $this->assertStringContainsString('Shopify returned 404', json_encode($run->getErrors()));
        $this->assertTrue(Variants::fromCompany($this->company)->where('sku', $sku)->exists());
    }

    public function testProductsAreRefusedWhenTheRegionHasNoDefaultWarehouse(): void
    {
        Warehouses::fromCompany($this->company)->where('regions_id', $this->region->getId())->update(['is_default' => 0]);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('has no default warehouse');

        $this->integrationCompany->importerFor('products', $this->kanvasUser)->prepare([]);
    }

    public function testCustomersBecomePeopleWithTheirDefaultAddress(): void
    {
        $run = $this->runCustomers([
            $this->shopifyCustomer(5001, 'Jane', 'jane+' . uniqid() . '@example.com'),
        ]);

        $person = $this->person(5001);

        $this->assertSame(1, $run->created);
        $this->assertSame('Jane', $person->firstname);
        $this->assertSame('123 Elm St.', $person->address()->first()?->address);
    }

    public function testImportingCustomersAgainUpdatesTheSamePerson(): void
    {
        $email = 'jane+' . uniqid() . '@example.com';
        $this->runCustomers([$this->shopifyCustomer(5001, 'Jane', $email)]);

        $run = $this->runCustomers([$this->shopifyCustomer(5001, 'Janet', $email)]);

        $this->assertSame(0, $run->created);
        $this->assertSame(1, $run->updated);
        $this->assertSame('Janet', $this->person(5001)->firstname);
    }

    public function testACustomerWithoutAnIdFailsAloneAndTheRestContinue(): void
    {
        $run = $this->runCustomers([
            ['first_name' => 'Nobody'],
            $this->shopifyCustomer(5002, 'Jane', 'jane+' . uniqid() . '@example.com'),
        ]);

        $this->assertSame(1, $run->created);
        $this->assertSame(1, $run->failed);
        $this->assertStringContainsString('Shopify customer is missing an id', json_encode($run->getErrors()));
    }

    /**
     * @param array<int, array|RuntimeException> $products Shopify's answer per product id
     */
    private function runProducts(array $products): IntegrationImport
    {
        return $this->runImport(
            'products',
            new class ($this->integrationCompany, $this->kanvasUser, $products) extends ShopifyProductsImporter {
                public function __construct(IntegrationsCompany $integrationCompany, Users $user, private readonly array $products)
                {
                    parent::__construct($integrationCompany, $user);
                }

                #[Override]
                protected function allPages(string $resource, array $params = []): array
                {
                    return array_map(fn (int $id) => ['id' => $id], array_keys($this->products));
                }

                #[Override]
                protected function shopifyProduct(string $productId): array
                {
                    $product = $this->products[(int) $productId];

                    return $product instanceof RuntimeException ? throw $product : $product;
                }
            }
        );
    }

    private function runCustomers(array $customers): IntegrationImport
    {
        return $this->runImport(
            'customers',
            new class ($this->integrationCompany, $this->kanvasUser, $customers) extends ShopifyCustomersImporter {
                public function __construct(IntegrationsCompany $integrationCompany, Users $user, private readonly array $customers)
                {
                    parent::__construct($integrationCompany, $user);
                }

                #[Override]
                protected function allPages(string $resource, array $params = []): array
                {
                    return $this->customers;
                }
            }
        );
    }

    private function runImport(string $entity, IntegrationImporter $importer): IntegrationImport
    {
        $run = IntegrationImport::create([
            'apps_id' => $this->integrationCompany->apps_id,
            'companies_id' => $this->company->getId(),
            'integrations_id' => $this->integrationCompany->integrations_id,
            'integrations_companies_id' => $this->integrationCompany->getId(),
            'users_id' => $this->kanvasUser->getId(),
            'entity' => $entity,
            'status' => IntegrationImportStatusEnum::QUEUED,
            'options' => [],
            'queued_at' => Carbon::now(),
        ]);

        new class ($run, $importer) extends RunIntegrationImportJob {
            public function __construct(IntegrationImport $run, private readonly IntegrationImporter $seamImporter)
            {
                parent::__construct($run);
            }

            #[Override]
            protected function importer(): IntegrationImporter
            {
                return $this->seamImporter;
            }
        }->handle();

        return $run->refresh();
    }

    private function shopifyProduct(int $id, string $sku, string $price): array
    {
        return [
            'id' => $id,
            'title' => 'Shopify Shirt',
            'body_html' => '<p>Soft cotton</p>',
            'handle' => 'shopify-shirt-' . strtolower($sku),
            'status' => 'active',
            'vendor' => 'Acme',
            'product_type' => 'Shirts',
            'tags' => 'cotton',
            'images' => [],
            'options' => [['name' => 'Size']],
            'variants' => [
                [
                    'id' => $id * 10,
                    'title' => 'M',
                    'sku' => $sku,
                    'price' => $price,
                    'compare_at_price' => null,
                    'inventory_item_id' => $id * 100,
                    'inventory_quantity' => 4,
                    'barcode' => null,
                    'option1' => 'M',
                ],
            ],
        ];
    }

    /**
     * The shape of the customers list: addresses, no default_address.
     */
    private function shopifyCustomer(int $id, string $firstName, string $email): array
    {
        return [
            'id' => $id,
            'first_name' => $firstName,
            'last_name' => 'Doe',
            'email' => $email,
            'phone' => null,
            'addresses' => [
                ['address1' => '9 Other Rd.', 'city' => 'Toronto', 'province' => 'Ontario', 'country' => 'Canada', 'zip' => 'M5V', 'default' => false],
                ['address1' => '123 Elm St.', 'city' => 'Ottawa', 'province' => 'Ontario', 'country' => 'Canada', 'zip' => 'K2H7A8', 'default' => true],
            ],
        ];
    }

    private function shopifyIntegration(): Integrations
    {
        return Integrations::where('name', IntegrationsEnum::SHOPIFY->value)->first() ?? Integrations::create([
            'apps_id' => 0,
            'name' => IntegrationsEnum::SHOPIFY->value,
            'handler' => ShopifyHandler::class,
            'type' => 'key',
        ]);
    }

    private function person(int $shopifyId): People
    {
        return People::getByCustomFieldTransactionSafe(
            CustomFieldEnum::SHOPIFY_CUSTOMER_ID->value,
            $shopifyId,
            $this->company
        );
    }
}
