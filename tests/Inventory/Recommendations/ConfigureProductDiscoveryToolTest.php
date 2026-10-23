<?php

declare(strict_types=1);

namespace Tests\Inventory\Recommendations;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Intelligence\Agents\Neuron\Tools\Inventory\ConfigureProductDiscoveryTool;
use Kanvas\Inventory\Recommendations\Enums\ConfigurationEnum;
use Kanvas\Users\Models\Users;
use PHPUnit\Framework\Attributes\Group;
use ReflectionProperty;
use Tests\TestCase;
use Tests\Traits\ManagesAppSettings;

#[Group('serial')]
class ConfigureProductDiscoveryToolTest extends TestCase
{
    use DatabaseTransactions;
    use ManagesAppSettings;

    protected function setUp(): void
    {
        parent::setUp();

        $this->rememberAppSettings(
            'products_search_engine',
            'app_custom_product_index',
            ConfigurationEnum::QUERY_LANGUAGES->value,
            ConfigurationEnum::EMBEDDING_MODEL->value,
            ConfigurationEnum::TYPESENSE_QUERY_BY->value,
            ConfigurationEnum::INTENT_LEXICON->value,
            ConfigurationEnum::SEMANTIC_PROFILE_STRATEGY->value,
        );
    }

    public function testItNeverSwitchesTheEngineTheAppAlreadyRuns(): void
    {
        foreach (['algolia', 'typesense', 'database'] as $engine) {
            app(Apps::class)->set('products_search_engine', $engine);

            $this->tool()->__invoke(catalog_language: 'es');

            $this->assertSame($engine, app(Apps::class)->get('products_search_engine'));
        }
    }

    public function testCatalogLanguageAlsoSetsTheQueryLanguagesAsAList(): void
    {
        app(Apps::class)->set('products_search_engine', 'algolia');

        $result = $this->tool()->__invoke(catalog_language: 'ES');

        $this->assertSame(['es', 'en'], ConfigurationEnum::QUERY_LANGUAGES->listFrom(app(Apps::class)));
        $this->assertSame('es, en', $result['applied'][ConfigurationEnum::QUERY_LANGUAGES->value]);
    }

    public function testAnEnglishCatalogGetsASingleLanguage(): void
    {
        $this->tool()->__invoke(catalog_language: 'en');

        $this->assertSame(['en'], ConfigurationEnum::QUERY_LANGUAGES->listFrom(app(Apps::class)));
    }

    public function testAnInvalidLanguageCodeIsNotStored(): void
    {
        app(Apps::class)->del(ConfigurationEnum::QUERY_LANGUAGES->value);

        $this->tool()->__invoke(catalog_language: 'spanish');

        $this->assertSame([], ConfigurationEnum::QUERY_LANGUAGES->listFrom(app(Apps::class)));
    }

    public function testAlgoliaKeepsItsStorefrontIndexAndSkipsTheTypesenseOnlyEmbedding(): void
    {
        $app = app(Apps::class);
        $app->set('products_search_engine', 'algolia');
        $app->set('app_custom_product_index', 'storefront_index');
        $app->del(ConfigurationEnum::EMBEDDING_MODEL->value);

        $result = $this->tool()->__invoke();

        $this->assertSame('storefront_index', $app->get('app_custom_product_index'));
        $this->assertNull($app->get(ConfigurationEnum::EMBEDDING_MODEL->value));
        $this->assertSame('algolia', $result['engine']);
    }

    public function testTypesenseStillGetsItsOwnIndexAndEmbedding(): void
    {
        $app = app(Apps::class);
        $app->set('products_search_engine', 'typesense');
        $app->del('app_custom_product_index');

        $this->tool()->__invoke();

        $this->assertSame('app' . $app->getId() . '_product_index', $app->get('app_custom_product_index'));
        $this->assertSame('ts/multilingual-e5-small', $app->get(ConfigurationEnum::EMBEDDING_MODEL->value));
        $this->assertSame('search_blurb,name,description,embedding', $app->get(ConfigurationEnum::TYPESENSE_QUERY_BY->value));
    }

    public function testTheDescriptionStaysALiteralReflectionCanRead(): void
    {
        $description = new ReflectionProperty(ConfigureProductDiscoveryTool::class, 'description')->getDefaultValue();

        $this->assertIsString($description);
        $this->assertStringNotContainsString('Points products at Typesense', $description);
    }

    private function tool(): ConfigureProductDiscoveryTool
    {
        /** @var Users $user */
        $user = auth()->user();

        return new ConfigureProductDiscoveryTool()
            ->withContext(app(Apps::class), $user->getCurrentCompany(), $user)
            ->forRequestingUser($user);
    }
}
