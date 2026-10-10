<?php

declare(strict_types=1);

namespace Tests\Souk\Integration;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Souk\Enums\ConfigurationEnum;
use Kanvas\Souk\Services\StorefrontProductUrlService;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

final class StorefrontProductUrlServiceTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'inventory'];

    private Apps $apps;
    private Companies $company;
    private Products $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->apps = app(Apps::class);
        $this->company = Companies::factory()->create();
        $this->product = Products::factory()
            ->withAppId($this->apps->getId())
            ->withCompanyId($this->company->getId())
            ->create(['slug' => 'blue-widget-' . uniqid()]);
    }

    protected function tearDown(): void
    {
        $this->company->del(ConfigurationEnum::STOREFRONT_PRODUCT_URL->value);
        $this->apps->del(ConfigurationEnum::STOREFRONT_PRODUCT_URL->value);

        parent::tearDown();
    }

    public function testReturnsNullWhenNoStoreUrlIsConfigured(): void
    {
        $this->assertNull(new StorefrontProductUrlService($this->company, $this->apps)->productUrl($this->product));
    }

    public function testRendersSlugAndIdPlaceholdersFromTheCompanyTemplate(): void
    {
        $this->company->set(ConfigurationEnum::STOREFRONT_PRODUCT_URL->value, 'https://shop.test/p/{slug}?ref={id}');

        $url = new StorefrontProductUrlService($this->company, $this->apps)->productUrl($this->product);

        $this->assertSame(
            'https://shop.test/p/' . $this->product->slug . '?ref=' . $this->product->getId(),
            $url
        );
    }

    public function testBareBaseUrlGetsTheSlugAppended(): void
    {
        $this->company->set(ConfigurationEnum::STOREFRONT_PRODUCT_URL->value, 'https://shop.test/products/');

        $url = new StorefrontProductUrlService($this->company, $this->apps)->productUrl($this->product);

        $this->assertSame('https://shop.test/products/' . $this->product->slug, $url);
    }

    #[Group('serial')]
    public function testFallsBackToTheAppTemplateAndTheCompanyOverridesIt(): void
    {
        $this->apps->set(ConfigurationEnum::STOREFRONT_PRODUCT_URL->value, 'https://app.test/{slug}');

        $this->assertSame(
            'https://app.test/' . $this->product->slug,
            new StorefrontProductUrlService($this->company, $this->apps)->productUrl($this->product)
        );

        $this->company->set(ConfigurationEnum::STOREFRONT_PRODUCT_URL->value, 'https://store.test/{slug}');

        $this->assertSame(
            'https://store.test/' . $this->product->slug,
            StorefrontProductUrlService::forProduct($this->product)->productUrl($this->product)
        );
    }
}
