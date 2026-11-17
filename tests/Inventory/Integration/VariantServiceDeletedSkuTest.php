<?php

declare(strict_types=1);

namespace Tests\Inventory\Integration;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Inventory\Variants\Models\Variants;
use Kanvas\Inventory\Variants\Services\VariantService;
use Tests\TestCase;

final class VariantServiceDeletedSkuTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = [null, 'inventory'];

    public function testSoftDeletedVariantOnAnotherProductDoesNotCaptureTheSku(): void
    {
        $app = app(Apps::class);
        $user = static::$cachedUser;
        $company = $user->getCurrentCompany();
        $sku = 'sf-location-' . uniqid();

        $oldProduct = Products::factory()->withAppId($app->getId())->withCompanyId($company->getId())->create();
        $deletedVariant = Variants::factory()->withAppId($app->getId())->withProductId($oldProduct->getId())->create();
        Variants::where('id', $deletedVariant->getId())->update([
            'sku' => $sku,
            'companies_id' => $company->getId(),
            'is_deleted' => 1,
        ]);

        $newProduct = Products::factory()->withAppId($app->getId())->withCompanyId($company->getId())->create();

        VariantService::createVariantsFromArray(
            $newProduct,
            [['name' => 'Test Property', 'sku' => $sku]],
            $user,
            false
        );

        $this->assertSame(
            1,
            Variants::where('products_id', $newProduct->getId())->where('sku', $sku)->where('is_deleted', 0)->count(),
        );
        $this->assertSame(
            $oldProduct->getId(),
            (int) Variants::withTrashed()->where('id', $deletedVariant->getId())->value('products_id'),
        );
        $this->assertSame(1, (int) Variants::withTrashed()->where('id', $deletedVariant->getId())->value('is_deleted'));
    }
}
