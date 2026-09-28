<?php

declare(strict_types=1);

namespace Tests\Inventory\Recommendations;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Inventory\Recommendations\Actions\HydrateRecommendationsAction;
use Kanvas\Souk\Enums\ConfigurationEnum as SoukConfigurationEnum;
use Tests\TestCase;

class HydrateRecommendationsActionTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = [null, 'inventory'];

    public function testExpandsIdsIntoFullProductPayload(): void
    {
        $app = app(Apps::class);
        $product = $this->makeProduct($app);
        $variant = $product->variants->first();

        $result = new HydrateRecommendationsAction($app, $product->company)->execute([
            [
                'product_id' => $product->getId(),
                'variant_id' => $variant->getId(),
                'reason' => 'Perfecto para alguien creativo',
            ],
        ]);

        $this->assertCount(1, $result);
        $this->assertSame($product->getId(), $result[0]['product']['id']);
        $this->assertSame($product->name, $result[0]['product']['name']);
        $this->assertSame($variant->getId(), $result[0]['variant']['id']);
        $this->assertSame('Perfecto para alguien creativo', $result[0]['reason']);

        // The frontend reads these keys straight off the payload — a rename here
        // is a breaking change, so pin the shape rather than just the values.
        $this->assertArrayHasKey('slug', $result[0]['product']);
        $this->assertArrayHasKey('files', $result[0]['product']);
        $this->assertArrayHasKey('categories', $result[0]['product']);
        $this->assertArrayHasKey('channel', $result[0]['variant']);
        $this->assertArrayHasKey('is_available', $result[0]['variant']['channel']);
        $this->assertArrayHasKey('quantity', $result[0]['variant']['channel']);
    }

    public function testPreservesTheOrderTheAgentReturned(): void
    {
        $app = app(Apps::class);
        $company = Companies::factory()->create();

        $first = $this->makeProduct($app, $company);
        $second = $this->makeProduct($app, $company);
        $third = $this->makeProduct($app, $company);

        // Deliberately not ascending id order — ranking is the agent's call and
        // the DB fetch must not reimpose its own.
        $result = new HydrateRecommendationsAction($app, $company)->execute([
            ['product_id' => $third->getId(), 'variant_id' => $third->variants->first()->getId()],
            ['product_id' => $first->getId(), 'variant_id' => $first->variants->first()->getId()],
            ['product_id' => $second->getId(), 'variant_id' => $second->variants->first()->getId()],
        ]);

        $this->assertSame(
            [$third->getId(), $first->getId(), $second->getId()],
            array_column(array_column($result, 'product'), 'id'),
        );
    }

    public function testDropsHallucinatedProductIds(): void
    {
        $app = app(Apps::class);
        $product = $this->makeProduct($app);

        $result = new HydrateRecommendationsAction($app, $product->company)->execute([
            ['product_id' => 999999999, 'variant_id' => 1, 'reason' => 'invented'],
            ['product_id' => $product->getId(), 'variant_id' => $product->variants->first()->getId()],
        ]);

        $this->assertCount(1, $result);
        $this->assertSame($product->getId(), $result[0]['product']['id']);
    }

    public function testFallsBackToFirstVariantWhenVariantDoesNotBelongToProduct(): void
    {
        $app = app(Apps::class);
        $company = Companies::factory()->create();

        $product = $this->makeProduct($app, $company);
        $otherProduct = $this->makeProduct($app, $company);

        $result = new HydrateRecommendationsAction($app, $company)->execute([
            [
                'product_id' => $product->getId(),
                'variant_id' => $otherProduct->variants->first()->getId(),
            ],
        ]);

        $this->assertCount(1, $result);
        $this->assertSame(
            $product->variants->first()->getId(),
            $result[0]['variant']['id'],
            'A wrong variant id should degrade to the product first variant, not drop the recommendation.',
        );
    }

    public function testDoesNotHydrateProductsFromAnotherCompanyWhenCrossCompanyIsOff(): void
    {
        $app = app(Apps::class);
        $original = $app->get(SoukConfigurationEnum::ALLOW_CROSS_COMPANY_VARIANTS->value);
        $app->set(SoukConfigurationEnum::ALLOW_CROSS_COMPANY_VARIANTS->value, 0);

        try {
            $foreignProduct = $this->makeProduct($app, Companies::factory()->create());
            $callerCompany = Companies::factory()->create();
            $ownProduct = $this->makeProduct($app, $callerCompany);

            $result = new HydrateRecommendationsAction($app, $callerCompany)->execute([
                ['product_id' => $foreignProduct->getId(), 'variant_id' => $foreignProduct->variants->first()->getId()],
                ['product_id' => $ownProduct->getId(), 'variant_id' => $ownProduct->variants->first()->getId()],
            ]);

            $this->assertCount(1, $result, 'Cross-company ids must not resolve when the app has not opted into cross-company variants.');
            $this->assertSame($ownProduct->getId(), $result[0]['product']['id']);
        } finally {
            $app->set(SoukConfigurationEnum::ALLOW_CROSS_COMPANY_VARIANTS->value, $original);
        }
    }

    public function testHydratesAcrossCompaniesWhenTheAppOptsIn(): void
    {
        $app = app(Apps::class);
        $original = $app->get(SoukConfigurationEnum::ALLOW_CROSS_COMPANY_VARIANTS->value);
        $app->set(SoukConfigurationEnum::ALLOW_CROSS_COMPANY_VARIANTS->value, 1);

        try {
            $foreignProduct = $this->makeProduct($app, Companies::factory()->create());
            $callerCompany = Companies::factory()->create();
            $ownProduct = $this->makeProduct($app, $callerCompany);

            $result = new HydrateRecommendationsAction($app, $callerCompany)->execute([
                ['product_id' => $foreignProduct->getId(), 'variant_id' => $foreignProduct->variants->first()->getId()],
                ['product_id' => $ownProduct->getId(), 'variant_id' => $ownProduct->variants->first()->getId()],
            ]);

            $this->assertCount(2, $result, 'souk_allow_cross_company_variants is an explicit opt-in to a shared catalog.');
        } finally {
            $app->set(SoukConfigurationEnum::ALLOW_CROSS_COMPANY_VARIANTS->value, $original);
        }
    }

    public function testReturnsEmptyForEmptyInput(): void
    {
        $app = app(Apps::class);

        $this->assertSame([], new HydrateRecommendationsAction($app, Companies::factory()->create())->execute([]));
    }

    private function makeProduct(Apps $app, ?Companies $company = null): Products
    {
        $company ??= Companies::factory()->create();

        /** @var Products $product */
        $product = Products::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create(['is_published' => 1, 'is_deleted' => 0]);

        return $product->load('variants');
    }
}
