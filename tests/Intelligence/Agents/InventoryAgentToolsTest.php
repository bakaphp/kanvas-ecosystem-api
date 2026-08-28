<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Intelligence\Agents\Laravel\Inventory\AgentInventoryAssistance;
use Kanvas\Intelligence\Agents\Laravel\Tools\Inventory\AttributeSearchTool;
use Kanvas\Intelligence\Agents\Laravel\Tools\Inventory\CategorySearchTool;
use Kanvas\Intelligence\Agents\Laravel\Tools\Inventory\InventorySearchTool;
use Kanvas\Intelligence\Agents\Laravel\Tools\Inventory\ListAvailableProductsTool;
use Kanvas\Intelligence\Agents\Laravel\Tools\Inventory\VariantSearchTool;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentType;
use Kanvas\Inventory\Attributes\Models\Attributes;
use Kanvas\Inventory\Categories\Models\Categories;
use Kanvas\Inventory\Products\Actions\CreateProductAction;
use Kanvas\Inventory\Products\DataTransferObject\Product;
use Kanvas\Inventory\Support\Setup as InventorySetup;
use Kanvas\Users\Models\Users;
use Laravel\Ai\Tools\Request;
use Tests\TestCase;

class InventoryAgentToolsTest extends TestCase
{
    use DatabaseTransactions;

    protected Apps $kanvasApp;
    protected Agent $agent;
    protected Users $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->kanvasApp = app(Apps::class);
        /** @var Users $user */
        $user = auth()->user();
        $this->user = $user;
        $company = $user->getCurrentCompany();

        $setup = new InventorySetup($this->kanvasApp, $user, $company);
        $setup->run();

        $agentType = AgentType::factory()
            ->withAppId($this->kanvasApp->id)
            ->create(['handler' => AgentInventoryAssistance::class]);

        $this->agent = Agent::factory()
            ->withAppId($this->kanvasApp->id)
            ->withCompanyId($company->id)
            ->create(['agent_type_id' => $agentType->id]);
    }

    public function testInventorySearchToolReturnsMatchingProducts(): void
    {
        $company = $this->user->getCurrentCompany();
        $uniqueName = 'Toyota Corolla Test ' . uniqid();

        $product = new CreateProductAction(
            new Product(
                app: $this->kanvasApp,
                company: $company,
                user: $this->user,
                name: $uniqueName,
                sku: 'TOY-' . uniqid(),
            ),
            $this->user
        )->execute();

        $result = (new InventorySearchTool())
            ->withContext($this->kanvasApp, $company)
            ->handle(new Request(['product_name' => $uniqueName]));

        $this->assertStringContainsString($product->name, (string) $result);
    }

    public function testInventorySearchToolReturnsNotFoundMessage(): void
    {
        $result = (new InventorySearchTool())
            ->withContext($this->kanvasApp, $this->user->getCurrentCompany())
            ->handle(new Request(['product_name' => 'zzz-nonexistent-xyz-' . uniqid()]));

        $this->assertStringContainsString('No products found', (string) $result);
    }

    public function testListAvailableProductsToolReturnsPublishedProducts(): void
    {
        $company = $this->user->getCurrentCompany();

        new CreateProductAction(
            new Product(
                app: $this->kanvasApp,
                company: $company,
                user: $this->user,
                name: 'Published Product ' . uniqid(),
                sku: 'PUB-' . uniqid(),
                is_published: true,
            ),
            $this->user
        )->execute();

        $result = (new ListAvailableProductsTool())
            ->withContext($this->kanvasApp, $company)
            ->handle(new Request(['is_published' => true, 'only_in_stock' => false, 'limit' => 10]));

        $data = json_decode((string) $result, true);
        $this->assertIsArray($data);
        $this->assertNotEmpty($data);
        foreach ($data as $item) {
            $this->assertTrue($item['is_published']);
        }
    }

    public function testListAvailableProductsToolFiltersOnlyInStock(): void
    {
        $company = $this->user->getCurrentCompany();

        new CreateProductAction(
            new Product(
                app: $this->kanvasApp,
                company: $company,
                user: $this->user,
                name: 'No Stock Product ' . uniqid(),
                sku: 'NSTK-' . uniqid(),
                is_published: true,
            ),
            $this->user
        )->execute();

        $result = (new ListAvailableProductsTool())
            ->withContext($this->kanvasApp, $company)
            ->handle(new Request(['is_published' => true, 'only_in_stock' => true, 'limit' => 10]));

        $decoded = json_decode((string) $result, true);

        if (is_array($decoded)) {
            foreach ($decoded as $item) {
                $this->assertGreaterThan(0, $item['total_stock']);
            }
        } else {
            $this->assertStringContainsString('No', (string) $result);
        }
    }

    public function testAttributeSearchToolListsAll(): void
    {
        $company = $this->user->getCurrentCompany();
        $uniqueKey = 'TestAttr' . uniqid();

        $attribute = Attributes::create([
            'apps_id' => $this->kanvasApp->getId(),
            'companies_id' => $company->getId(),
            'users_id' => $this->user->getId(),
            'name' => $uniqueKey,
            'slug' => strtolower($uniqueKey),
        ]);

        $result = (new AttributeSearchTool())
            ->withContext($this->kanvasApp, $company)
            ->handle(new Request(['keyword' => '']));

        $data = json_decode((string) $result, true);

        // An unfiltered call returns one page, never the whole catalog, so the row just created is
        // only guaranteed to be counted — the tool has to say how many it left out.
        $this->assertGreaterThanOrEqual($data['showing'], $data['total']);
        $this->assertSame($data['showing'], count($data['attributes']));

        $found = (new AttributeSearchTool())
            ->withContext($this->kanvasApp, $company)
            ->handle(new Request(['keyword' => $attribute->name]));

        $this->assertContains($attribute->name, array_column(json_decode((string) $found, true)['attributes'], 'name'));
    }

    public function testAttributeSearchToolFiltersByKeyword(): void
    {
        $company = $this->user->getCurrentCompany();
        $uniqueKey = 'UniqueAttr' . uniqid();

        Attributes::create([
            'apps_id' => $this->kanvasApp->getId(),
            'companies_id' => $company->getId(),
            'users_id' => $this->user->getId(),
            'name' => $uniqueKey,
            'slug' => strtolower($uniqueKey),
        ]);

        $result = (new AttributeSearchTool())
            ->withContext($this->kanvasApp, $company)
            ->handle(new Request(['keyword' => $uniqueKey]));

        $data = json_decode((string) $result, true);
        $this->assertSame(1, $data['total']);
        $this->assertEquals($uniqueKey, $data['attributes'][0]['name']);
    }

    public function testAttributeSearchToolReturnsNotFoundForUnknownKeyword(): void
    {
        $result = (new AttributeSearchTool())
            ->withContext($this->kanvasApp, $this->user->getCurrentCompany())
            ->handle(new Request(['keyword' => 'zzz-nonexistent-' . uniqid()]));

        $this->assertStringContainsString('No attributes found', (string) $result);
    }

    public function testCategorySearchToolListsAll(): void
    {
        $company = $this->user->getCurrentCompany();
        $uniqueName = 'TestCat' . uniqid();

        $category = Categories::create([
            'apps_id' => $this->kanvasApp->getId(),
            'companies_id' => $company->getId(),
            'users_id' => $this->user->getId(),
            'name' => $uniqueName,
            'slug' => strtolower($uniqueName),
        ]);

        $result = (new CategorySearchTool())
            ->withContext($this->kanvasApp, $company)
            ->handle(new Request(['keyword' => '']));

        $data = json_decode((string) $result, true);

        $this->assertGreaterThanOrEqual($data['showing'], $data['total']);
        $this->assertSame($data['showing'], count($data['categories']));

        $found = (new CategorySearchTool())
            ->withContext($this->kanvasApp, $company)
            ->handle(new Request(['keyword' => $category->name]));

        $this->assertContains($category->name, array_column(json_decode((string) $found, true)['categories'], 'name'));
    }

    public function testCategorySearchToolFiltersByKeyword(): void
    {
        $company = $this->user->getCurrentCompany();
        $uniqueName = 'UniqueCat' . uniqid();

        Categories::create([
            'apps_id' => $this->kanvasApp->getId(),
            'companies_id' => $company->getId(),
            'users_id' => $this->user->getId(),
            'name' => $uniqueName,
            'slug' => strtolower($uniqueName),
        ]);

        $result = (new CategorySearchTool())
            ->withContext($this->kanvasApp, $company)
            ->handle(new Request(['keyword' => $uniqueName]));

        $data = json_decode((string) $result, true);
        $this->assertSame(1, $data['total']);
        $this->assertEquals($uniqueName, $data['categories'][0]['name']);
    }

    public function testVariantSearchToolFindsByName(): void
    {
        $company = $this->user->getCurrentCompany();
        $uniqueName = 'VarTest' . uniqid();

        new CreateProductAction(
            new Product(
                app: $this->kanvasApp,
                company: $company,
                user: $this->user,
                name: 'Product for variant ' . uniqid(),
                sku: 'PVRT-' . uniqid(),
                variants: [['name' => $uniqueName, 'sku' => 'SKU-VAR-' . uniqid()]],
            ),
            $this->user
        )->execute();

        $result = (new VariantSearchTool())
            ->withContext($this->kanvasApp, $company)
            ->handle(new Request(['keyword' => $uniqueName]));

        $data = json_decode((string) $result, true);
        $this->assertIsArray($data);
        $this->assertNotEmpty($data);
        $this->assertEquals($uniqueName, $data[0]['name']);
    }

    public function testVariantSearchToolRequiresKeyword(): void
    {
        $result = (new VariantSearchTool())
            ->withContext($this->kanvasApp, $this->user->getCurrentCompany())
            ->handle(new Request(['keyword' => '']));

        $this->assertStringContainsString('Please provide a keyword', (string) $result);
    }

    public function testAgentChatReturnsResponse(): void
    {
        AgentInventoryAssistance::fake(['Found 3 products in stock.']);

        $agent = AgentInventoryAssistance::make()->forUser($this->user);
        $response = $agent->prompt('What products are available?');

        $this->assertNotEmpty($response->text);
        $this->assertEquals('Found 3 products in stock.', $response->text);
    }
}
