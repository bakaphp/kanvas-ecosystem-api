<?php

declare(strict_types=1);

namespace Tests\Inventory\Recommendations;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Inventory\Categories\Actions\CreateCategory;
use Kanvas\Inventory\Categories\DataTransferObject\Categories as CategoriesDto;
use Kanvas\Inventory\Categories\Models\Categories;
use Kanvas\Inventory\Channels\Models\Channels;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Inventory\Recommendations\Actions\RecommendProductsAction;
use Kanvas\Inventory\Recommendations\Contracts\ProductDiscoveryInterface;
use Kanvas\Inventory\Recommendations\DataTransferObject\ProductIntent;
use Kanvas\Inventory\Recommendations\Enums\ConfigurationEnum;
use Kanvas\Inventory\Recommendations\Services\ProductDiscoveryResolver;
use Kanvas\Inventory\Support\Setup as InventorySetup;
use Kanvas\Inventory\Variants\Models\Variants;
use Kanvas\Inventory\Variants\Models\VariantsChannels;
use Kanvas\Inventory\Variants\Models\VariantsWarehouses;
use Kanvas\Inventory\Warehouses\Models\Warehouses;
use Kanvas\Souk\Enums\ConfigurationEnum as SoukConfigurationEnum;
use Kanvas\Users\Models\Users;
use RuntimeException;
use Tests\TestCase;

class RecommendProductsActionTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = [null, 'inventory'];

    protected function setUp(): void
    {
        parent::setUp();

        // Candidate ids are cached per query; a stale entry from a sibling case
        // would mask what the engine actually returned.
        Cache::flush();
    }

    public function testHydratesTheEnginesIdsIntoProductPayloads(): void
    {
        $app = app(Apps::class);
        $company = Companies::factory()->create();
        $product = $this->makeProduct($app, $company);

        $result = new RecommendProductsAction($app, $company, $this->engineReturning([$product->getId()]))
            ->execute('algo para una persona creativa');

        $this->assertCount(1, $result);
        $this->assertSame($product->getId(), $result[0]['product']['id']);
        $this->assertArrayHasKey('variants', $result[0]);
        $this->assertArrayHasKey('channel', $result[0]['variants'][0]);
    }

    public function testPreservesTheEnginesRanking(): void
    {
        $app = app(Apps::class);
        $company = Companies::factory()->create();

        $first = $this->makeProduct($app, $company);
        $second = $this->makeProduct($app, $company);
        $third = $this->makeProduct($app, $company);

        $ranked = [$third->getId(), $first->getId(), $second->getId()];

        $result = new RecommendProductsAction($app, $company, $this->engineReturning($ranked))
            ->execute('algo bonito');

        $this->assertSame($ranked, array_column(array_column($result, 'product'), 'id'));
    }

    public function testDoesNotHydrateIdsFromAnotherCompany(): void
    {
        $app = app(Apps::class);
        $original = $app->get(SoukConfigurationEnum::ALLOW_CROSS_COMPANY_VARIANTS->value);
        $app->set(SoukConfigurationEnum::ALLOW_CROSS_COMPANY_VARIANTS->value, 0);

        try {
            $company = Companies::factory()->create();
            $own = $this->makeProduct($app, $company);
            $foreign = $this->makeProduct($app, Companies::factory()->create());

            // A compromised or mis-scoped index is exactly this: ids the caller
            // must not be allowed to see. The DB read is what stops it.
            $result = new RecommendProductsAction(
                $app,
                $company,
                $this->engineReturning([$foreign->getId(), $own->getId()]),
            )->execute('algo bonito');

            $this->assertCount(1, $result);
            $this->assertSame($own->getId(), $result[0]['product']['id']);
        } finally {
            $app->set(SoukConfigurationEnum::ALLOW_CROSS_COMPANY_VARIANTS->value, $original);
        }
    }

    public function testDropsIdsThatNoLongerResolve(): void
    {
        $app = app(Apps::class);
        $company = Companies::factory()->create();
        $product = $this->makeProduct($app, $company);

        // The index is eventually consistent — a deleted product can still be in it.
        $result = new RecommendProductsAction($app, $company, $this->engineReturning([999999999, $product->getId()]))
            ->execute('algo bonito');

        $this->assertCount(1, $result);
    }

    public function testUnpricedProductSurvivesABudgetFlaggedUnavailable(): void
    {
        $app = app(Apps::class);
        $company = Companies::factory()->create();
        $product = $this->makeProduct($app, $company);

        // A product with no price cannot be shown to break a budget, so it is
        // returned flagged unavailable rather than silently dropped — the
        // shopper still sees it and can be notified when it is back.
        $result = new RecommendProductsAction($app, $company, $this->engineReturning([$product->getId()]))
            ->execute('algo bonito menos de $50');

        $this->assertCount(1, $result);
        $this->assertFalse($result[0]['variants'][0]['channel']['is_available']);
        $this->assertNull($result[0]['variants'][0]['channel']['price']);
    }

    public function testCapsHowManyResultsShareTheSameProductName(): void
    {
        $app = app(Apps::class);
        $company = Companies::factory()->create();

        // A dealer lists the same model once per colour; they embed almost
        // identically, so without a cap they take the entire page.
        $duplicates = [
            $this->makeProduct($app, $company, 'Kia Seltos EX'),
            $this->makeProduct($app, $company, 'Kia Seltos EX'),
            $this->makeProduct($app, $company, 'Kia Seltos EX'),
            $this->makeProduct($app, $company, 'Kia Seltos EX'),
        ];
        $distinct = $this->makeProduct($app, $company, 'Kia Sorento MQ4');

        $ids = [...array_map(fn (Products $p): int => $p->getId(), $duplicates), $distinct->getId()];

        $result = new RecommendProductsAction($app, $company, $this->engineReturning($ids))
            ->execute('un carro nuevo', 3);

        $names = array_column(array_column($result, 'product'), 'name');

        $this->assertCount(3, $result);
        $this->assertSame(2, count(array_filter($names, fn (string $n): bool => $n === 'Kia Seltos EX')));
        $this->assertContains('Kia Sorento MQ4', $names, 'A distinct product must be promoted over a third duplicate.');
    }

    public function testGroupsAProductFamilyUnderOneCapNotOnePerExactName(): void
    {
        $app = app(Apps::class);
        $company = Companies::factory()->create();

        // Seed catalogs number their variations. Four names, one product as far as
        // a shopper is concerned — grouping on the whole name gave each its own
        // budget and the family took the page.
        $family = [
            $this->makeProduct($app, $company, 'Perfume Premium 31'),
            $this->makeProduct($app, $company, 'Perfume Premium 37'),
            $this->makeProduct($app, $company, 'Perfume Premium 38'),
            $this->makeProduct($app, $company, 'Perfume Premium 44'),
        ];
        $other = $this->makeProduct($app, $company, 'Delantal Home Chef');

        $ids = [...array_map(fn (Products $p): int => $p->getId(), $family), $other->getId()];

        $result = new RecommendProductsAction($app, $company, $this->engineReturning($ids))
            ->execute('un regalo bonito', 3);

        $names = array_column(array_column($result, 'product'), 'name');
        $perfumes = array_filter($names, static fn (string $n): bool => str_starts_with($n, 'Perfume'));

        $this->assertCount(2, $perfumes);
        $this->assertContains('Delantal Home Chef', $names);
    }

    public function testKeepsDistinctProductsThatShareOnlyTheirSecondWord(): void
    {
        $app = app(Apps::class);
        $company = Companies::factory()->create();

        // "Pulsera Classic" and "Bolso Classic" share a word but are not a family;
        // grouping must not merge them just because the cap got coarser.
        $bracelet = $this->makeProduct($app, $company, 'Pulsera Classic 35');
        $bag = $this->makeProduct($app, $company, 'Bolso Classic 27');
        $second = $this->makeProduct($app, $company, 'Pulsera Classic 36');

        $result = new RecommendProductsAction(
            $app,
            $company,
            $this->engineReturning([$bracelet->getId(), $second->getId(), $bag->getId()]),
        )->execute('un regalo para ella', 3);

        $this->assertContains('Bolso Classic 27', array_column(array_column($result, 'product'), 'name'));
    }

    public function testDroppedDuplicatesStillFillThePageWhenNothingElseMatches(): void
    {
        $app = app(Apps::class);
        $company = Companies::factory()->create();

        $ids = array_map(
            fn (int $i): int => $this->makeProduct($app, $company, 'Kia Seltos EX')->getId(),
            range(1, 4),
        );

        // A catalogue of near-identical stock should still return a full page —
        // the cap reorders, it does not throw results away.
        $result = new RecommendProductsAction($app, $company, $this->engineReturning($ids))
            ->execute('un carro nuevo', 4);

        $this->assertCount(4, $result);
    }

    public function testDropsProductsInAnExcludedCategory(): void
    {
        $app = app(Apps::class);
        $original = $app->get(ConfigurationEnum::EXCLUDED_CATEGORIES->value);
        $app->set(ConfigurationEnum::EXCLUDED_CATEGORIES->value, ['Envoltura']);

        try {
            $company = Companies::factory()->create();
            $wrap = $this->makeProduct($app, $company, 'Envoltura roja');
            $gift = $this->makeProduct($app, $company, 'Delantal Home Chef');

            // Gift wrap scores highly on a gift query and is never the gift. The
            // category is matched case-insensitively and accent-folded.
            $wrap->categories()->attach($this->makeCategory($app, $company, 'ENVOLTURA')->getId());

            $result = new RecommendProductsAction($app, $company, $this->engineReturning([$wrap->getId(), $gift->getId()]))
                ->execute('regalos para mi suegra');

            $names = array_column(array_column($result, 'product'), 'name');

            $this->assertNotContains('Envoltura roja', $names);
            $this->assertContains('Delantal Home Chef', $names);
        } finally {
            $app->set(ConfigurationEnum::EXCLUDED_CATEGORIES->value, $original);
        }
    }

    public function testPromotesBuyableProductsOverUnavailableOnes(): void
    {
        $app = app(Apps::class);
        /** @var Users $user */
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        new InventorySetup($app, $user, $company)->run();

        $unpriced = $this->makeProduct($app, $company, 'Perfume Premium 38');
        $buyable = $this->makeProduct($app, $company, 'Delantal Home Chef');
        $this->makeBuyable($app, $company, $buyable);

        // The engine ranks the unpriced one first. It stays in the result — a thin
        // catalogue still has to fill the page — but it loses the top slot.
        $result = new RecommendProductsAction(
            $app,
            $company,
            $this->engineReturning([$unpriced->getId(), $buyable->getId()]),
        )->execute('regalo para alguien que cocina');

        $this->assertSame(
            ['Delantal Home Chef', 'Perfume Premium 38'],
            array_column(array_column($result, 'product'), 'name'),
        );
    }

    public function testEmptyQueryShortCircuitsWithoutHittingTheEngine(): void
    {
        $app = app(Apps::class);
        $company = Companies::factory()->create();

        $engine = new class () implements ProductDiscoveryInterface {
            public bool $called = false;

            public function search(ProductIntent $intent, int $limit, ?array $tasteVector = null): array
            {
                $this->called = true;

                return [];
            }
        };

        $this->assertSame([], new RecommendProductsAction($app, $company, $engine)->execute('   '));
        $this->assertFalse($engine->called);
    }

    public function testCachesCandidateIdsForARepeatedQuery(): void
    {
        $app = app(Apps::class);
        $company = Companies::factory()->create();
        $product = $this->makeProduct($app, $company);

        $engine = new class ([$product->getId()]) implements ProductDiscoveryInterface {
            public int $calls = 0;

            public function __construct(private readonly array $ids)
            {
            }

            public function search(ProductIntent $intent, int $limit, ?array $tasteVector = null): array
            {
                $this->calls++;

                return $this->ids;
            }
        };

        $action = new RecommendProductsAction($app, $company, $engine);
        $action->execute('un regalo para mi mama');
        $action->execute('Un Regalo Para Mi MAMA');

        // Gift-finder traffic repeats heavily, and the key is the normalized
        // sentence — casing must not miss the cache.
        $this->assertSame(1, $engine->calls);
    }

    public function testPersonalizedResultsAreNotServedFromTheSharedCache(): void
    {
        $app = app(Apps::class);
        $company = Companies::factory()->create();
        $product = $this->makeProduct($app, $company);

        $engine = new class ([$product->getId()]) implements ProductDiscoveryInterface {
            public int $calls = 0;

            public function __construct(private readonly array $ids)
            {
            }

            public function search(ProductIntent $intent, int $limit, ?array $tasteVector = null): array
            {
                $this->calls++;

                return $this->ids;
            }
        };

        $action = new RecommendProductsAction($app, $company, $engine);
        $action->execute('algo bonito', 8, [0.1, 0.2]);
        $action->execute('algo bonito', 8, [0.1, 0.2]);

        $this->assertSame(2, $engine->calls, 'A per-shopper result must never land in a cache shared by every shopper.');
    }

    public function testFailingEngineFallsBackToKeywordSearch(): void
    {
        $app = app(Apps::class);
        $company = Companies::factory()->create();

        $product = $this->makeProduct($app, $company);
        $product->name = 'Reloj de lujo';
        $product->saveQuietly();

        $failing = new class () implements ProductDiscoveryInterface {
            public function search(ProductIntent $intent, int $limit, ?array $tasteVector = null): array
            {
                throw new RuntimeException('typesense unreachable');
            }
        };

        // Injected engines are the test seam, so the action returns empty rather
        // than silently swapping in a different backend mid-test.
        $this->assertSame([], new RecommendProductsAction($app, $company, $failing)->execute('reloj'));

        // The resolver-driven path is the one that must degrade to SQL.
        $result = new RecommendProductsAction($app, $company, $this->resolverFallback($app, $company))
            ->execute('reloj');

        $this->assertNotEmpty($result);
        $this->assertSame($product->getId(), $result[0]['product']['id']);
    }

    private function resolverFallback(Apps $app, Companies $company): ProductDiscoveryInterface
    {
        return new ProductDiscoveryResolver($app, $company)->fallback();
    }

    /**
     * @param list<int> $ids
     */
    private function engineReturning(array $ids): ProductDiscoveryInterface
    {
        return new class ($ids) implements ProductDiscoveryInterface {
            public function __construct(private readonly array $ids)
            {
            }

            public function search(ProductIntent $intent, int $limit, ?array $tasteVector = null): array
            {
                return $this->ids;
            }
        };
    }

    private function makeBuyable(Apps $app, Companies $company, Products $product): void
    {
        /** @var Variants $variant */
        $variant = $product->variants()->where('is_deleted', 0)->firstOrFail();

        $warehouse = Warehouses::fromApp($app)->fromCompany($company)->firstOrFail();
        $channel = Channels::getDefault($company, $app);

        $variantWarehouse = VariantsWarehouses::updateOrCreate(
            [
                'products_variants_id' => $variant->getId(),
                'warehouses_id' => $warehouse->getId(),
            ],
            [
                'quantity' => 5,
                'price' => 25.00,
                'sku' => $variant->sku ?? 'SKU-' . fake()->unique()->uuid(),
                'position' => 1,
                'is_default' => 1,
            ],
        );

        VariantsChannels::updateOrCreate(
            [
                'product_variants_warehouse_id' => $variantWarehouse->getId(),
                'channels_id' => $channel->getId(),
            ],
            [
                'products_variants_id' => $variant->getId(),
                'warehouses_id' => $warehouse->getId(),
                'price' => 25.00,
                'discounted_price' => 0,
                'is_published' => 1,
            ],
        );
    }

    private function makeCategory(Apps $app, Companies $company, string $name): Categories
    {
        return new CreateCategory(
            new CategoriesDto(
                app: $app,
                company: $company,
                user: auth()->user(),
                name: $name,
            ),
            auth()->user(),
        )->execute();
    }

    private function makeProduct(Apps $app, Companies $company, ?string $name = null): Products
    {
        /** @var Products $product */
        $product = Products::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create(array_filter([
                'name' => $name,
                'is_published' => 1,
                'is_deleted' => 0,
            ], static fn (mixed $v): bool => $v !== null));

        return $product;
    }
}
