<?php

declare(strict_types=1);

namespace Tests\Inventory\Recommendations;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Inventory\Recommendations\Enums\AudienceEnum;
use Kanvas\Inventory\Recommendations\Enums\SearchFieldEnum;
use Kanvas\Users\Models\Users;
use Tests\TestCase;

class ProductSearchableAudienceTest extends TestCase
{
    use DatabaseTransactions;
    use PinsSearchEngine;

    protected $connectionsToTransact = [null, 'inventory'];

    /**
     * App-wide, not just products: creating a product also indexes its variants.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->pinSearchEngine('search_engine', 'products_search_engine', 'variants_search_engine');
    }

    protected function tearDown(): void
    {
        $this->restoreSearchEngine();

        parent::tearDown();
    }

    public function testIndexesTheEnrichedAudienceFlat(): void
    {
        $product = $this->makeProduct();
        $product->addAttributes($this->user(), [
            ['name' => SearchFieldEnum::AUDIENCE->value, 'value' => ['female']],
        ]);

        // Nested under `attributes` this cannot be a scalar filter_by, which is
        // the whole reason it is copied to the top level.
        $this->assertSame(['female'], $product->fresh()->toSearchableArray()['audience']);
    }

    public function testIndexesUnknownRatherThanNothingWhenNeverEnriched(): void
    {
        // An empty array has no dependable "is empty" filter in Typesense, so an
        // un-enriched product says so by name and the filter admits it.
        $this->assertSame(
            [AudienceEnum::UNKNOWN->value],
            $this->makeProduct()->toSearchableArray()['audience'],
        );
    }

    private function user(): Users
    {
        /** @var Users $user */
        $user = auth()->user();

        return $user;
    }

    private function makeProduct(): Products
    {
        /** @var Products $product */
        $product = Products::factory()
            ->withAppId(app(Apps::class)->getId())
            ->withCompanyId($this->user()->getCurrentCompany()->getId())
            ->create(['is_published' => 1, 'is_deleted' => 0]);

        return $product;
    }
}
