<?php

declare(strict_types=1);

namespace Tests\Inventory\Recommendations;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Inventory\Recommendations\Actions\RecommendProductsAction;
use Kanvas\Inventory\Recommendations\Contracts\ProductDiscoveryInterface;
use Kanvas\Inventory\Recommendations\Contracts\ServesSearchDocuments;
use Kanvas\Inventory\Recommendations\DataTransferObject\ProductIntent;
use Kanvas\Inventory\Recommendations\Discovery\DiscoveryQuery;
use Kanvas\Inventory\Recommendations\Discovery\NumericFilter;
use Kanvas\Inventory\Recommendations\Enums\ConfigurationEnum;
use Kanvas\Inventory\Recommendations\Services\IntentLexiconService;
use Kanvas\Inventory\Recommendations\Services\ProductRecommendationPresenterService;
use Kanvas\Souk\Enums\ConfigurationEnum as SoukConfigurationEnum;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;
use Tests\Traits\ManagesAppSettings;

/**
 * With a third-party engine, discovery must answer from the engine's documents and
 * leave the product tables alone; search traffic must not land on the database.
 */
#[Group('serial')]
class DocumentDiscoveryTest extends TestCase
{
    use DatabaseTransactions;
    use ManagesAppSettings;

    private const int CHANNEL_ID = 9001;

    protected $connectionsToTransact = [null, 'inventory'];

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        $this->setAppSettings([SoukConfigurationEnum::ALLOW_CROSS_COMPANY_VARIANTS->value => 0]);
    }

    public function testRendersFromTheDocumentsWithoutReadingProductTables(): void
    {
        $company = auth()->user()->getCurrentCompany();
        $this->warmDefaultChannel($company);

        DB::connection('inventory')->enableQueryLog();
        DB::connection('inventory')->flushQueryLog();

        $result = new RecommendProductsAction(app(Apps::class), $company, $this->engine([$this->document($company, 501)]))
            ->execute('un reloj elegante');

        $this->assertSame([], DB::connection('inventory')->getQueryLog(), 'A document-serving engine must not trigger product reads.');
        $this->assertSame(501, $result[0]['product']['id']);
        $this->assertSame(15000.0, $result[0]['variants'][0]['channel']['price']);
        $this->assertTrue($result[0]['variants'][0]['channel']['is_available']);
        $this->assertSame([['id' => 77, 'url' => 'https://cdn.test/a.jpg', 'name' => 'a.jpg']], $result[0]['product']['files']);
    }

    public function testDropsADocumentFromAnotherCompany(): void
    {
        $company = auth()->user()->getCurrentCompany();
        $this->warmDefaultChannel($company);
        $foreign = Companies::factory()->create();

        $result = new RecommendProductsAction(
            app(Apps::class),
            $company,
            $this->engine([$this->document($foreign, 601), $this->document($company, 602)]),
        )->execute('reloj');

        $this->assertSame([602], array_column(array_column($result, 'product'), 'id'), 'The engine filters by tenant; this is the second guard.');
    }

    public function testHardLimitsFilterVariantsFromTheDocument(): void
    {
        $company = auth()->user()->getCurrentCompany();
        $this->warmDefaultChannel($company);
        $this->setAppSettings([
            ConfigurationEnum::NUMERIC_ATTRIBUTES->value => ['mileage' => ['attribute' => 'Mileage', 'unit' => 'mi']],
        ]);

        $document = $this->document($company, 701, [
            $this->variant(11, 15000.0, mileage: '120,000 mi'),
            $this->variant(12, 18000.0, mileage: '62000'),
            $this->variant(13, 35000.0, mileage: '40000'),
        ]);

        $intent = ProductIntent::fromSentence('an SUV', new IntentLexiconService(app(Apps::class)))->withOverrides(
            maxPrice: 20000.0,
            numericFilters: [new NumericFilter('mileage', max: 100000.0)],
        );

        $result = new RecommendProductsAction(app(Apps::class), $company, $this->engine([$document]))->executeIntent($intent);

        $this->assertSame([12], array_column($result[0]['variants'], 'id'), 'Only the variant within budget AND under the mileage limit.');
    }

    public function testATrimmedRecordDegradesInsteadOfFailing(): void
    {
        $company = auth()->user()->getCurrentCompany();
        $presenter = new ProductRecommendationPresenterService(app(Apps::class), $company);
        $this->warmDefaultChannel($company);

        $rendered = $presenter->document([
            'id' => 801,
            'name' => 'Reloj',
            'in_stock' => true,
            'files' => [['url' => 'https://cdn.test/old.jpg']],
            'variants' => [['id' => 21, 'name' => 'Negro', 'channels' => [['id' => self::CHANNEL_ID, 'price' => 99]]]],
        ]);

        $this->assertSame([], $rendered['product']['files'], 'A file indexed before ids were added is skipped, not rendered without one.');
        $this->assertTrue($rendered['variants'][0]['channel']['is_available'], 'No indexed quantity falls back to the product\'s in_stock.');
    }

    public function testTheDatabasePathLoadsAPageInABoundedNumberOfQueries(): void
    {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();
        $ids = [];

        foreach (range(1, 8) as $i) {
            /** @var Products $product */
            $product = Products::factory()->withAppId($app->getId())->withCompanyId($company->getId())->create(['is_published' => 1]);
            $ids[] = $product->getId();
        }

        $this->warmDefaultChannel($company);

        foreach (['inventory', 'ecosystem'] as $connection) {
            DB::connection($connection)->enableQueryLog();
            DB::connection($connection)->flushQueryLog();
        }

        new ProductRecommendationPresenterService($app, $company)->loadProducts($ids);

        $queries = count(DB::connection('inventory')->getQueryLog()) + count(DB::connection('ecosystem')->getQueryLog());
        $this->assertLessThanOrEqual(12, $queries, 'Files and attributes load per page, not per product and variant.');
    }

    private function warmDefaultChannel(mixed $company): void
    {
        Cache::put('product-discovery:default-channel:' . app(Apps::class)->getId() . ':' . $company->getId(), self::CHANNEL_ID, 600);
    }

    /**
     * @param list<array<string, mixed>>|null $variants
     */
    private function document(Companies $company, int $id, ?array $variants = null): array
    {
        return [
            'id' => (string) $id,
            'name' => 'Producto ' . $id,
            'slug' => 'producto-' . $id,
            'apps_id' => app(Apps::class)->getId(),
            'companies_id' => $company->getId(),
            'in_stock' => true,
            'files' => [['id' => 77, 'url' => 'https://cdn.test/a.jpg', 'name' => 'a.jpg']],
            'categories' => [['id' => 5, 'name' => 'Relojes']],
            'attributes' => [],
            'variants' => $variants ?? [$this->variant(1, 15000.0)],
        ];
    }

    private function variant(int $id, float $price, ?string $mileage = null): array
    {
        return [
            'id' => (string) $id,
            'name' => 'Variante ' . $id,
            'sku' => 'SKU-' . $id,
            'quantity' => 3,
            'channels' => [['id' => self::CHANNEL_ID, 'price' => $price, 'discounted_price' => 0]],
            'attributes' => $mileage === null ? [] : ['Mileage' => $mileage],
            'files' => [],
        ];
    }

    /**
     * @param list<array<string, mixed>> $documents
     */
    private function engine(array $documents): ProductDiscoveryInterface
    {
        return new class ($documents) implements ProductDiscoveryInterface, ServesSearchDocuments {
            public function __construct(private readonly array $documents)
            {
            }

            public function search(DiscoveryQuery $query, int $limit, ?array $tasteVector = null): array
            {
                return array_map(static fn (array $document): int => (int) $document['id'], $this->documents);
            }

            public function searchDocuments(DiscoveryQuery $query, int $limit, ?array $tasteVector = null): array
            {
                return $this->documents;
            }

            public function supportsVectors(): bool
            {
                return false;
            }

            public function canFilterOn(string $field): bool
            {
                return true;
            }
        };
    }
}
