<?php

declare(strict_types=1);

namespace Tests\Inventory\Integration;

use App\GraphQL\Inventory\Mutations\Categories\CategoryMediaMutation;
use App\GraphQL\Inventory\Mutations\Products\ProductMediaMutation;
use App\GraphQL\Inventory\Mutations\Variants\VariantMediaMutation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Kanvas\Apps\Models\Apps;
use Kanvas\Inventory\Categories\Models\Categories;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Inventory\Variants\Models\Variants;
use Tests\TestCase;

/**
 * Resolver-level, not multipart, so it runs under paratest. The inventory suite must not list
 * `inventory` in connectionsToTransact (see tests/CLAUDE.md), so rows are left behind on purpose.
 */
final class UploadFileToInventoryTest extends TestCase
{
    public function testAFileLandsOnTheProduct(): void
    {
        $product = $this->makeProduct();

        $result = new ProductMediaMutation()->attachFileToProduct(null, [
            'id' => $product->getId(),
            'file' => UploadedFile::fake()->create('spec-sheet.pdf', 10),
        ]);

        $this->assertInstanceOf(Products::class, $result);
        $this->assertSame(['spec-sheet.pdf'], $this->fileNames($product));
    }

    public function testAFileLandsOnTheVariantAndTheVariantComesBack(): void
    {
        $variant = Variants::factory()->withProductId($this->makeProduct()->getId())->create();

        $result = new VariantMediaMutation()->attachFileToVariant(null, [
            'id' => $variant->getId(),
            'file' => UploadedFile::fake()->create('variant.jpg', 10),
        ]);

        $this->assertInstanceOf(Variants::class, $result);
        $this->assertSame($variant->getId(), $result->getId());
        $this->assertSame(['variant.jpg'], $this->fileNames($variant));
    }

    public function testAFileLandsOnTheCategoryAndTheCategoryComesBack(): void
    {
        $company = auth()->user()->getCurrentCompany();
        $category = Categories::create([
            'apps_id' => app(Apps::class)->getId(),
            'companies_id' => $company->getId(),
            'users_id' => auth()->user()->getId(),
            'name' => 'Upload category ' . uniqid(),
            'slug' => 'upload-category-' . uniqid(),
        ]);

        $result = new CategoryMediaMutation()->attachFileToCategory(null, [
            'id' => $category->getId(),
            'file' => UploadedFile::fake()->create('banner.jpg', 10),
        ]);

        $this->assertInstanceOf(Categories::class, $result);
        $this->assertSame($category->getId(), $result->getId());
        $this->assertSame(['banner.jpg'], $this->fileNames($category));
    }

    private function makeProduct(): Products
    {
        return Products::factory()
            ->withCompanyId(auth()->user()->getCurrentCompany()->getId())
            ->create();
    }

    /**
     * @return list<string>
     */
    private function fileNames(Model $entity): array
    {
        $names = $entity->files()->pluck('filesystem.name')->all();
        sort($names);

        return $names;
    }
}
