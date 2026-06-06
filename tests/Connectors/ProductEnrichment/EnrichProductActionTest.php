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
use Kanvas\Inventory\Support\Setup as InventorySetup;
use Tests\TestCase;

class EnrichProductActionTest extends TestCase
{
    use DatabaseTransactions;

    // NOT 'social': tags write to social.tags_entities THROUGH the inventory
    // connection, so the inventory transaction already rolls it back. Transacting
    // social separately deadlocks the two connections (lock wait timeout).
    protected array $connectionsToTransact = ['mysql', 'intelligence', 'inventory'];

    public function testEnrichesProductWritesTagsAndBlurbThenSkipsUnchanged(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        new InventorySetup($app, $user, $company)->run();

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

        // Tags write to social.tags_entities THROUGH the inventory connection; read
        // them back the same way (cross-schema, inventory connection) so we see the
        // in-transaction write. 'not-a-real-tag' must have been dropped by clean().
        $tagNames = DB::connection('inventory')
            ->table('social.tags_entities as te')
            ->join('social.tags as t', 't.id', '=', 'te.tags_id')
            ->where('te.entity_id', $product->getId())
            ->where('te.taggable_type', Products::class)
            ->where('te.is_deleted', 0)
            ->pluck('t.name')
            ->all();

        $this->assertSame(['elegant'], $tagNames);

        // Unchanged content → the hash gate skips the LLM on the second run.
        $second = new EnrichProductAction($product)->execute();
        $this->assertSame('unchanged', $second['status']);
    }
}
