<?php

declare(strict_types=1);

namespace Tests\Inventory;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Inventory\Products\Models\Products;
use Tests\TestCase;

final class ProductPhotosCountTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'ecosystem', 'inventory'];

    public function testGetPhotosCountOnlyCountsImageFiles(): void
    {
        $product = $this->createProduct();

        $product->addFileFromUrl('https://example.com/photo-' . uniqid() . '.jpg', 'photo-1');
        $product->addFileFromUrl('https://example.com/photo-' . uniqid() . '.png', 'photo-2');
        $product->addFileFromUrl('https://example.com/brochure-' . uniqid() . '.pdf', 'brochure');

        $this->assertSame(2, $product->getPhotosCount());
    }

    public function testGetPhotosCountIsZeroWithOnlyNonImageFiles(): void
    {
        $product = $this->createProduct();

        $product->addFileFromUrl('https://example.com/manual-' . uniqid() . '.pdf', 'manual');

        $this->assertSame(0, $product->getPhotosCount());
    }

    public function testGetPhotosCountIsZeroWithNoFiles(): void
    {
        $product = $this->createProduct();

        $this->assertSame(0, $product->getPhotosCount());
    }

    private function createProduct(): Products
    {
        $app = app(Apps::class);
        $company = Companies::factory()->create(['users_id' => auth()->user()->getId()]);

        /** @var Products $product */
        $product = Products::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create(['is_published' => 1, 'is_deleted' => 0]);

        return $product;
    }
}
