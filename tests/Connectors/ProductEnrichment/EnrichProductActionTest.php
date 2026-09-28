<?php

declare(strict_types=1);

namespace Tests\Connectors\ProductEnrichment;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\ProductEnrichment\Actions\EnrichProductAction;
use Kanvas\Connectors\ProductEnrichment\Agents\ProductEnrichmentAgent;
use Kanvas\Connectors\ProductEnrichment\Enums\CustomFieldEnum;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentType;
use Kanvas\Inventory\Products\Actions\CreateProductAction;
use Kanvas\Inventory\Products\DataTransferObject\Product;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Inventory\Recommendations\Enums\ConfigurationEnum;
use Kanvas\Inventory\Recommendations\Enums\SemanticProfileStrategyEnum;
use Kanvas\Inventory\Support\Setup as InventorySetup;
use Kanvas\Social\Tags\Models\Tag;
use Tests\TestCase;

class EnrichProductActionTest extends TestCase
{
    use DatabaseTransactions;

    // NOT 'social': tags write to social.tags_entities THROUGH the inventory
    // connection, so the inventory transaction already rolls it back. Transacting
    // social separately deadlocks the two connections (lock wait timeout).
    protected array $connectionsToTransact = ['mysql', 'intelligence', 'inventory'];

    private mixed $originalStrategy = null;

    // App settings live in Redis, outside the DB transaction, and set(key, null)
    // is a no-op there — so an unset strategy is restored as an explicit generic.
    protected function tearDown(): void
    {
        app(Apps::class)->set(
            ConfigurationEnum::SEMANTIC_PROFILE_STRATEGY->value,
            is_string($this->originalStrategy) ? $this->originalStrategy : SemanticProfileStrategyEnum::GENERIC->value,
        );

        parent::tearDown();
    }

    public function testEnrichesProductWritesTagsAndBlurbThenSkipsUnchanged(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        new InventorySetup($app, $user, $company)->run();

        $this->originalStrategy = $app->get(ConfigurationEnum::SEMANTIC_PROFILE_STRATEGY->value);
        $app->set(ConfigurationEnum::SEMANTIC_PROFILE_STRATEGY->value, SemanticProfileStrategyEnum::GENERIC->value);

        $type = AgentType::factory()
            ->withAppId($app->getId())
            ->create(['handler' => ProductEnrichmentAgent::class]);
        Agent::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create(['agent_type_id' => $type->getId(), 'user_id' => $user->getId()]);

        $product = new CreateProductAction(
            new Product(
                app: $app,
                company: $company,
                user: $user,
                name: 'Pearl Necklace ' . uniqid(),
                sku: 'PE-' . uniqid(),
            ),
            $user,
        )->execute();

        // The LLM returns one out-of-vocab tag — it must be dropped by clean().
        ProductEnrichmentAgent::fake([[
            'audience' => ['female'],
            'occasion' => ['birthday', 'anniversary'],
            'interests' => ['jewelry'],
            'tags' => ['elegant', 'not-a-real-tag'],
            'blurb_es' => 'Un collar elegante para ella.',
            'blurb_en' => 'An elegant necklace for her.',
        ]]);

        $result = new EnrichProductAction($product)->execute();

        $this->assertSame('enriched', $result['status']);
        $this->assertStringContainsString('elegant necklace', $result['blurb']);

        $this->assertStringContainsString(
            'elegant necklace',
            (string) $product->get(CustomFieldEnum::BLURB->value),
        );
        $this->assertNotNull($product->get(CustomFieldEnum::ENRICHMENT_HASH->value));

        // The pivot row is written into social.tags_entities THROUGH the inventory
        // connection (the pivot inherits the parent model's connection), so read the
        // tags_id back on inventory with useWritePdo() to see the in-transaction write.
        // The Tag rows themselves live on the social connection — resolve their names
        // there rather than cross-schema joining them via the inventory PDO, which is
        // not reliably visible across connections in CI. 'not-a-real-tag' must have
        // been dropped by clean().
        $tagIds = DB::connection('inventory')
            ->table('social.tags_entities')
            ->where('entity_id', $product->getId())
            ->where('taggable_type', Products::class)
            ->where('is_deleted', 0)
            ->useWritePdo()
            ->pluck('tags_id')
            ->all();

        $tagNames = Tag::query()
            ->whereIn('id', $tagIds)
            ->pluck('name')
            ->sort()
            ->values()
            ->all();

        $this->assertSame(['elegant'], $tagNames);

        // Unchanged content → the hash gate skips the LLM on the second run.
        $second = new EnrichProductAction($product)->execute();
        $this->assertSame('unchanged', $second['status']);
    }

    public function testFlippingTheFramingStrategyReopensTheHashGate(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        new InventorySetup($app, $user, $company)->run();

        $this->originalStrategy = $app->get(ConfigurationEnum::SEMANTIC_PROFILE_STRATEGY->value);
        $app->set(ConfigurationEnum::SEMANTIC_PROFILE_STRATEGY->value, SemanticProfileStrategyEnum::GENERIC->value);

        $type = AgentType::factory()
            ->withAppId($app->getId())
            ->create(['handler' => ProductEnrichmentAgent::class]);
        Agent::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create(['agent_type_id' => $type->getId(), 'user_id' => $user->getId()]);

        $product = new CreateProductAction(
            new Product(
                app: $app,
                company: $company,
                user: $user,
                name: 'Apron ' . uniqid(),
                sku: 'AP-' . uniqid(),
            ),
            $user,
        )->execute();

        // No tags on either pass: a tag written through the inventory connection and
        // then deleted through the social one deadlocks the two transactions.
        ProductEnrichmentAgent::fake([[
            'audience' => ['female'],
            'occasion' => [],
            'interests' => [],
            'tags' => [],
            'blurb_es' => 'Un delantal resistente para cocinar a diario.',
            'blurb_en' => 'A sturdy apron for everyday cooking.',
        ]]);

        $this->assertSame('enriched', new EnrichProductAction($product)->execute()['status']);

        // Flipping the framing rewrites the prompt, so every blurb written under the
        // old one is stale — the gate has to reopen without anyone clearing hashes.
        $app->set(ConfigurationEnum::SEMANTIC_PROFILE_STRATEGY->value, SemanticProfileStrategyEnum::GIFT->value);

        ProductEnrichmentAgent::fake([[
            'audience' => ['female'],
            'occasion' => ['birthday'],
            'interests' => [],
            'tags' => [],
            'blurb_es' => 'Para tu mama, que disfruta cocinar los domingos.',
            'blurb_en' => 'For your mother, who enjoys cooking on Sundays.',
        ]]);

        $reframed = new EnrichProductAction($product->refresh())->execute();

        $this->assertSame('enriched', $reframed['status']);
        $this->assertStringContainsString('your mother', $reframed['blurb']);
    }
}
