<?php

declare(strict_types=1);

namespace Tests\Social\Integration;

use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Inventory\Warehouses\Models\Warehouses;
use Kanvas\Social\Interactions\Models\EntityInteractions;
use Kanvas\Social\Support\Setup;
use Tests\TestCase;

final class InteractionsTest extends TestCase
{
    public function testEntityLikeOtherEntity(): void
    {
        $company = auth()->user()->getCurrentCompany();
        $setupCompany = new Setup(
            app(Apps::class),
            auth()->user(),
            $company
        );
        $setupCompany->run();

        $warehouse = Warehouses::firstOrFail();
        $product = Products::firstOrFail();

        $this->assertInstanceOf(
            EntityInteractions::class,
            $product->like($warehouse, 'This is a test note')
        );
    }

    public function testEntityHasLikedOtherEntity(): void
    {
        $warehouse = Warehouses::firstOrFail();
        $product = Products::firstOrFail();
        $product->like($warehouse, 'This is a test note');

        $this->assertTrue(
            $product->hasLiked($warehouse)
        );
    }

    public function testEntityTotalLikesOfOtherEntity(): void
    {
        $warehouse = Warehouses::firstOrFail();
        $product = Products::firstOrFail();
        $product->like($warehouse, 'This is a test note');

        $this->assertGreaterThan(
            0,
            $product->likes()->count()
        );
    }

    public function testEntityUnLikeOtherEntity(): void
    {
        $warehouse = Warehouses::firstOrFail();
        $product = Products::firstOrFail();

        $this->assertTrue(
            $product->unLike($warehouse)
        );
    }

    public function testEntityTotalDislikesOfOtherEntity(): void
    {
        $warehouse = Warehouses::firstOrFail();
        $product = Products::firstOrFail();
        $product->dislike($warehouse, 'This is a test note');

        $this->assertGreaterThan(
            0,
            $product->dislikes()->count()
        );
    }

    public function testEntityUnLikeDislikeOtherEntity(): void
    {
        $warehouse = Warehouses::firstOrFail();
        $product = Products::firstOrFail();

        $this->assertTrue(
            $product->unLike($warehouse, null, true)
        );
    }

    public function testEntityNewInteraction()
    {
        $people = People::firstOrFail();
        $product = Products::firstOrFail();

        $this->assertInstanceOf(
            EntityInteractions::class,
            $people->addInteraction($product, 'view', 'This is a test note')
        );
    }
}
