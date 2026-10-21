<?php

declare(strict_types=1);

namespace Tests\Inventory\Recommendations\Enrichment;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentType;
use Kanvas\Inventory\Products\Actions\CreateProductAction;
use Kanvas\Inventory\Products\DataTransferObject\Product;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Inventory\Recommendations\Enrichment\Actions\EnrichProductAction;
use Kanvas\Inventory\Recommendations\Enrichment\Agents\ProductEnrichmentAgent;
use Kanvas\Inventory\Recommendations\Enrichment\Enums\CustomFieldEnum;
use Kanvas\Inventory\Recommendations\Enums\ConfigurationEnum;
use Kanvas\Inventory\Recommendations\Enums\SearchFieldEnum;
use Kanvas\Inventory\Recommendations\Enums\SemanticProfileStrategyEnum;
use Kanvas\Inventory\Support\Setup as InventorySetup;
use Tests\TestCase;
use Tests\Traits\ManagesAppSettings;

class EnrichProductActionTest extends TestCase
{
    use DatabaseTransactions;
    use ManagesAppSettings;

    // Not 'inventory': CreateProductAction retries its own deadlocks only on a transaction it opened
    // itself, and this file runs in the parallel pass (tests/CLAUDE.md). The product rows leak.
    protected array $connectionsToTransact = ['mysql', 'intelligence', 'social'];

    public function testEnrichesProductWritesTagsAndBlurbThenSkipsUnchanged(): void
    {
        $product = $this->makeProductWithAgent();

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
            (string) $product->get(SearchFieldEnum::BLURB->value),
        );
        $this->assertNotNull($product->get(CustomFieldEnum::ENRICHMENT_HASH->value));

        // 'not-a-real-tag' must have been dropped by clean().
        $tagNames = $product->tags()->pluck('name')->sort()->values()->all();

        $this->assertSame(['elegant'], $tagNames);

        // Unchanged content → the hash gate skips the LLM on the second run.
        $second = new EnrichProductAction($product)->execute();
        $this->assertSame('unchanged', $second['status']);
    }

    public function testFlippingTheFramingStrategyReopensTheHashGate(): void
    {
        $product = $this->makeProductWithAgent();

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
        app(Apps::class)->set(ConfigurationEnum::SEMANTIC_PROFILE_STRATEGY->value, SemanticProfileStrategyEnum::GIFT->value);

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

    public function testALongHtmlDescriptionReachesThePromptStrippedAndCapped(): void
    {
        $product = $this->makeProductWithAgent(
            '<p>Cordless drill.</p><p>' . str_repeat('word ', 600) . '</p><script>tail()</script>',
        );

        ProductEnrichmentAgent::fake([$this->structuredResponse()]);

        new EnrichProductAction($product)->execute();

        ProductEnrichmentAgent::assertPrompted(function ($prompt): bool {
            preg_match('/description: (.*?)\ncategories:/s', $prompt->prompt, $matches);
            $description = $matches[1] ?? '';

            return str_starts_with($description, "Cordless drill.\nword word")
                && ! str_contains($description, '<p>')
                && mb_strlen($description) <= 1500
                && str_ends_with($description, '…');
        });
    }

    public function testTheHashStillCoversTheRawDescriptionBeyondTheCap(): void
    {
        $head = '<p>' . str_repeat('word ', 400) . '</p>';
        $product = $this->makeProductWithAgent($head . 'old tail');

        ProductEnrichmentAgent::fake([$this->structuredResponse(), $this->structuredResponse()]);

        $this->assertSame('enriched', new EnrichProductAction($product)->execute()['status']);
        $this->assertSame('unchanged', new EnrichProductAction($product)->execute()['status']);

        $product->description = $head . 'new tail';

        $this->assertSame('enriched', new EnrichProductAction($product)->execute()['status']);
    }

    public function testReportsTheUsageOfTheCall(): void
    {
        $product = $this->makeProductWithAgent('A plain description.');

        ProductEnrichmentAgent::fake([$this->structuredResponse()]);

        $usage = new EnrichProductAction($product)->execute()['usage'];

        $this->assertSame(0, $usage['input_tokens']);
        $this->assertSame(0, $usage['output_tokens']);
        $this->assertNotEmpty($usage['model']);
    }

    private function makeProductWithAgent(?string $description = null): Products
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        new InventorySetup($app, $user, $company)->run();

        $this->rememberAppSettings(ConfigurationEnum::SEMANTIC_PROFILE_STRATEGY->value);
        $app->set(ConfigurationEnum::SEMANTIC_PROFILE_STRATEGY->value, SemanticProfileStrategyEnum::GENERIC->value);

        $type = AgentType::factory()
            ->withAppId($app->getId())
            ->create(['handler' => ProductEnrichmentAgent::class]);
        Agent::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create(['agent_type_id' => $type->getId(), 'user_id' => $user->getId()]);

        return new CreateProductAction(
            new Product(
                app: $app,
                company: $company,
                user: $user,
                name: 'Drill ' . uniqid(),
                sku: 'DR-' . uniqid(),
                description: $description,
            ),
            $user,
        )->execute();
    }

    /**
     * @return array<string, mixed>
     */
    private function structuredResponse(): array
    {
        return [
            'audience' => ['unisex'],
            'occasion' => [],
            'interests' => [],
            'tags' => [],
            'blurb_es' => 'Un taladro inalambrico.',
            'blurb_en' => 'A cordless drill.',
        ];
    }
}
