<?php

declare(strict_types=1);

namespace Tests\Baka\Search;

use Baka\Search\Activities\PushEntityToSecondaryIndexActivity;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\Models\StoredWorkflow;
use Tests\Connectors\Traits\HasIntegrationCompany;
use Tests\TestCase;

final class PushEntityToSecondaryIndexActivityTest extends TestCase
{
    use DatabaseTransactions;
    use HasIntegrationCompany;

    protected $connectionsToTransact = [null, 'inventory'];

    public function testMissingIndexNameFailsWorkflowWithoutAnyIntegrationLookup(): void
    {
        $product = $this->createProduct();

        $result = $this->activity()->execute($product, app(Apps::class), []);

        $this->assertFalse($result['result']);
        $this->assertSame('index_name is required in params', $result['message']);
    }

    public function testUnknownSearchEngineIsReportedAsAFailureNotAnUncaughtThrow(): void
    {
        $product = $this->createProduct();
        $this->setUpInternalIntegration($product);

        $result = $this->activity()->execute($product, app(Apps::class), [
            'index_name' => 'whatever_index',
            'search_engine' => 'meilisearch',
        ]);

        $this->assertFalse($result['result']);
        $this->assertStringContainsString('not implemented', $result['message']);
    }

    public function testIndexesEntityIntoTypesenseWhenRequested(): void
    {
        $product = $this->createProduct();
        $this->setUpInternalIntegration($product);
        $this->createTypesenseCollection('activity_typesense_collection');

        $app = app(Apps::class);

        try {
            $app->set('typesense_search_settings', $this->localTypesenseSettings());

            $result = $this->activity()->execute($product, $app, [
                'index_name' => 'activity_typesense_collection',
                'search_engine' => 'typesense',
            ]);

            $this->assertTrue($result['result']);
            $this->assertSame('activity_typesense_collection', $result['index']);
            $this->assertTypesenseCollectionHasDocument('activity_typesense_collection', (string) $product->id);
        } finally {
            $app->deleteHash('typesense_search_settings');
            $this->deleteTypesenseCollection('activity_typesense_collection');
        }
    }

    public function testUnpublishedEntityIsRemovedFromTheSecondaryIndexInstead(): void
    {
        $product = $this->createProduct();
        $this->setUpInternalIntegration($product);
        $this->createTypesenseCollection('activity_typesense_removal');

        $app = app(Apps::class);

        try {
            $app->set('typesense_search_settings', $this->localTypesenseSettings());

            $this->activity()->execute($product, $app, [
                'index_name' => 'activity_typesense_removal',
                'search_engine' => 'typesense',
            ]);
            $this->assertTypesenseCollectionHasDocument('activity_typesense_removal', (string) $product->id);

            $product->is_published = 0;
            $product->save();

            $result = $this->activity()->execute($product, $app, [
                'index_name' => 'activity_typesense_removal',
                'search_engine' => 'typesense',
            ]);

            $this->assertTrue($result['result']);
            $this->assertStringContainsString('removed', $result['message']);
            $this->assertTypesenseCollectionMissesDocument('activity_typesense_removal', (string) $product->id);
        } finally {
            $app->deleteHash('typesense_search_settings');
            $this->deleteTypesenseCollection('activity_typesense_removal');
        }
    }

    private function activity(): PushEntityToSecondaryIndexActivity
    {
        return new PushEntityToSecondaryIndexActivity(
            0,
            now()->toDateTimeString(),
            new StoredWorkflow(),
            []
        );
    }

    private function setUpInternalIntegration(Products $product): void
    {
        $user = auth()->user();

        $this->setIntegration(
            app(Apps::class),
            IntegrationsEnum::INTERNAL,
            'Kanvas\\Connectors\\Internal\\Handlers\\InternalHandler',
            $product->company,
            $user
        );
    }

    private function createProduct(): Products
    {
        $company = Companies::factory()->create();

        /** @var Products $product */
        $product = Products::factory()
            ->withAppId(app(Apps::class)->getId())
            ->withCompanyId($company->getId())
            ->create(['is_published' => 1, 'is_deleted' => 0]);

        $product->load('variants');

        return $product;
    }

    private function localTypesenseSettings(): array
    {
        return [
            'typesense_api_key' => 'xyz',
            'typesense_nodes' => [['host' => 'typesense', 'port' => 8108, 'path' => '/', 'protocol' => 'http']],
        ];
    }

    private function createTypesenseCollection(string $name): void
    {
        Http::withHeaders(['X-TYPESENSE-API-KEY' => 'xyz'])
            ->post('http://typesense:8108/collections', [
                'name' => $name,
                'fields' => [['name' => '.*', 'type' => 'auto']],
                'enable_nested_fields' => true,
            ]);
    }

    private function deleteTypesenseCollection(string $name): void
    {
        Http::withHeaders(['X-TYPESENSE-API-KEY' => 'xyz'])->delete("http://typesense:8108/collections/{$name}");
    }

    private function assertTypesenseCollectionHasDocument(string $collection, string $documentId): void
    {
        $response = Http::withHeaders(['X-TYPESENSE-API-KEY' => 'xyz'])
            ->get("http://typesense:8108/collections/{$collection}/documents/{$documentId}");

        $this->assertTrue($response->successful(), "Expected document {$documentId} in collection {$collection}");
    }

    private function assertTypesenseCollectionMissesDocument(string $collection, string $documentId): void
    {
        $response = Http::withHeaders(['X-TYPESENSE-API-KEY' => 'xyz'])
            ->get("http://typesense:8108/collections/{$collection}/documents/{$documentId}");

        $this->assertSame(404, $response->status(), "Expected document {$documentId} to be gone from {$collection}");
    }
}
