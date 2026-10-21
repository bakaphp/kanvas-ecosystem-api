<?php

declare(strict_types=1);

namespace Tests\Inventory\Recommendations;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Inventory\Recommendations\DataTransferObject\ProductIntent;
use Kanvas\Inventory\Recommendations\Discovery\DiscoveryQuery;
use Kanvas\Inventory\Recommendations\Discovery\DiscoveryQueryBuilder;
use Kanvas\Inventory\Recommendations\Services\IntentLexiconService;
use Kanvas\Inventory\Recommendations\Services\SearchTermTokenizerService;
use Kanvas\Inventory\Recommendations\Services\SqlProductDiscoveryService;
use Kanvas\Souk\Enums\ConfigurationEnum as SoukConfigurationEnum;
use Tests\TestCase;
use Tests\Traits\ManagesAppSettings;

class SqlProductDiscoveryServiceTest extends TestCase
{
    use DatabaseTransactions;
    use ManagesAppSettings;

    protected $connectionsToTransact = [null, 'inventory'];

    public function testANumericSkuFindsItsProductInsteadOfTheWholeCatalog(): void
    {
        [$app, $company] = [app(Apps::class), Companies::factory()->create()];
        $target = $this->productWithSku($company, 'Filtro de aceite', '123456');
        $this->productWithSku($company, 'Bujia de encendido', '999111');

        $this->assertSame([$target->getId()], $this->service($app, $company)->search($this->intent('123456'), 10));
    }

    public function testAShortModelCodeFindsItsProduct(): void
    {
        [$app, $company] = [app(Apps::class), Companies::factory()->create()];
        $target = $this->productWithSku($company, 'BMW X5 2020', 'X5');
        $this->productWithSku($company, 'Audi Q7 2021', 'Q7');

        $this->assertSame([$target->getId()], $this->service($app, $company)->search($this->intent('X5'), 10));
    }

    public function testAnEmptiedQueryWithNoExactMatchReturnsNothing(): void
    {
        [$app, $company] = [app(Apps::class), Companies::factory()->create()];
        $this->productWithSku($company, 'Reloj de lujo', 'RL-1');

        $this->assertSame([], $this->service($app, $company)->search($this->intent('ZZ9'), 10));
        $this->assertSame([], $this->service($app, $company)->search($this->intent('un regalo para mi'), 10));
    }

    public function testExactMatchesComeBeforeKeywordMatches(): void
    {
        [$app, $company] = [app(Apps::class), Companies::factory()->create()];
        $keyword = $this->productWithSku($company, 'Filtro generico', 'FG-1');
        $exact = $this->productWithSku($company, 'Pieza', 'AB123');

        $ids = $this->service($app, $company)->search($this->intent('filtro AB123'), 10);

        $this->assertSame([$exact->getId(), $keyword->getId()], $ids);
    }

    public function testDoesNotMatchAnotherCompanysSku(): void
    {
        $app = app(Apps::class);
        $this->setAppSettings([SoukConfigurationEnum::ALLOW_CROSS_COMPANY_VARIANTS->value => 0]);

        $mine = Companies::factory()->create();
        $other = Companies::factory()->create();
        $this->productWithSku($other, 'Filtro ajeno', '555000');

        $this->assertSame([], $this->service($app, $mine)->search($this->intent('555000'), 10));
    }

    private function service(Apps $app, Companies $company): SqlProductDiscoveryService
    {
        return new SqlProductDiscoveryService(
            $app,
            $company,
            new SearchTermTokenizerService($app),
        );
    }

    private function intent(string $sentence): DiscoveryQuery
    {
        $app = app(Apps::class);
        $intent = ProductIntent::fromSentence($sentence, new IntentLexiconService($app));

        return new DiscoveryQueryBuilder($app, auth()->user()->getCurrentCompany())
            ->build($intent, $this->service($app, auth()->user()->getCurrentCompany()));
    }

    private function productWithSku(Companies $company, string $name, string $sku): Products
    {
        /** @var Products $product */
        $product = Products::factory()
            ->withAppId(app(Apps::class)->getId())
            ->withCompanyId($company->getId())
            ->create([
                'name' => $name,
                'description' => '',
                'is_published' => 1,
                'is_deleted' => 0,
            ]);

        $product->variants()->update(['sku' => $sku]);

        return $product;
    }
}
