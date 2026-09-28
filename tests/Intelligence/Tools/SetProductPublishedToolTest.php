<?php

declare(strict_types=1);

namespace Tests\Intelligence\Tools;

use Kanvas\Apps\Models\Apps;
use Kanvas\Intelligence\Agents\Neuron\Tools\Inventory\SetProductPublishedTool;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Inventory\Variants\Models\Variants;
use Tests\TestCase;

class SetProductPublishedToolTest extends TestCase
{
    /**
     * Products and Variants are both Scout-searchable; the product factory creates a variant on
     * afterCreating and publish()/unPublish() save the product — each fires a MakeSearchable job
     * that hits Typesense. Tests don't need the index, so mute syncing for both models.
     */
    private function withoutSearch(callable $callback): void
    {
        Products::withoutSyncingToSearch(fn () => Variants::withoutSyncingToSearch($callback));
    }

    public function testUnpublishesAPublishedProduct(): void
    {
        $this->withoutSearch(function (): void {
            $app = app(Apps::class);
            $user = auth()->user();
            $company = $user->getCurrentCompany();

            $product = Products::factory()
                ->company($company->getId())
                ->create(['is_published' => true]);

            $result = new SetProductPublishedTool()
                ->withContext($app, $company, $user)
                ->__invoke(product_id: $product->getId(), published: false);

            $this->assertTrue($result['updated']);
            $this->assertFalse($result['is_published']);
            $this->assertFalse((bool) Products::getById($product->getId())->is_published);
        });
    }

    public function testPublishesAnUnpublishedProduct(): void
    {
        $this->withoutSearch(function (): void {
            $app = app(Apps::class);
            $user = auth()->user();
            $company = $user->getCurrentCompany();

            $product = Products::factory()
                ->company($company->getId())
                ->create(['is_published' => false]);

            $result = new SetProductPublishedTool()
                ->withContext($app, $company, $user)
                ->__invoke(product_id: $product->getId(), published: true);

            $this->assertTrue($result['updated']);
            $this->assertTrue($result['is_published']);
            $this->assertTrue((bool) Products::getById($product->getId())->is_published);
        });
    }

    public function testAlreadyInStateIsANoOp(): void
    {
        $this->withoutSearch(function (): void {
            $app = app(Apps::class);
            $user = auth()->user();
            $company = $user->getCurrentCompany();

            $product = Products::factory()
                ->company($company->getId())
                ->create(['is_published' => true]);

            $result = new SetProductPublishedTool()
                ->withContext($app, $company, $user)
                ->__invoke(product_id: $product->getId(), published: true);

            $this->assertFalse($result['updated']);
            $this->assertStringContainsString('already', $result['message']);
        });
    }

    public function testHallucinatedProductIdReturnsStructuredError(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $result = new SetProductPublishedTool()
            ->withContext($app, $company, $user)
            ->__invoke(product_id: 999999999, published: false);

        $this->assertSame('error', $result['status']);
        $this->assertArrayNotHasKey('updated', $result);
    }
}
