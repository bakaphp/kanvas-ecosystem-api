<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Intelligence\Agents\Neuron\Tools\Inventory\ListAvailableProductsTool;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Souk\Enums\ConfigurationEnum;
use Tests\TestCase;

final class ListAvailableProductsToolStorefrontUrlTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'inventory'];

    private Apps $apps;
    private Companies $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->apps = app(Apps::class);
        $this->company = Companies::factory()->create();
    }

    protected function tearDown(): void
    {
        $this->company->del(ConfigurationEnum::STOREFRONT_PRODUCT_URL->value);

        parent::tearDown();
    }

    public function testEveryListedProductCarriesItsStoreUrlWhenTheStoreIsConfigured(): void
    {
        $this->company->set(ConfigurationEnum::STOREFRONT_PRODUCT_URL->value, 'https://shop.test/items/{slug}');

        $product = Products::factory()
            ->withAppId($this->apps->getId())
            ->withCompanyId($this->company->getId())
            ->create(['slug' => 'red-widget-' . uniqid(), 'is_published' => 1]);

        $tool = new ListAvailableProductsTool()->withContext($this->apps, $this->company, auth()->user());

        $rows = collect($tool(is_published: true, limit: 50))->keyBy('id');

        $this->assertSame('https://shop.test/items/' . $product->slug, $rows[$product->getId()]['url']);
    }

    public function testUrlIsNullWhenTheStoreHasNoTemplate(): void
    {
        $product = Products::factory()
            ->withAppId($this->apps->getId())
            ->withCompanyId($this->company->getId())
            ->create(['is_published' => 1]);

        $tool = new ListAvailableProductsTool()->withContext($this->apps, $this->company, auth()->user());

        $rows = collect($tool(is_published: true, limit: 50))->keyBy('id');

        $this->assertArrayHasKey('url', $rows[$product->getId()]);
        $this->assertNull($rows[$product->getId()]['url']);
    }
}
