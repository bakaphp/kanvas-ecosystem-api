<?php

declare(strict_types=1);

namespace Tests\Inventory;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Inventory\Products\Models\Products;
use Tests\TestCase;

class ProductShouldBeSearchableTest extends TestCase
{
    use DatabaseTransactions;

    public function testShouldBeSearchableDoesNotFatalOnOrphanedProductWithNoCompany(): void
    {
        $app = app(Apps::class);

        /** @var Products $product */
        $product = Products::factory()
            ->withAppId($app->getId())
            ->create(['is_published' => 1, 'is_deleted' => 0]);

        // Reproduce an orphan: companies_id points at a company that doesn't
        // exist, so the `company` relation resolves to null. Before the `?->`
        // guard this fataled with "Call to a member function get() on null"
        // and killed the whole-app reindex on the first bad row.
        $product->companies_id = 999999999;
        $product->unsetRelation('company');

        $this->assertNull($product->company, 'Test setup should produce a company-less product.');

        // Must not throw — orphan has no price requirement, so it falls through
        // to isPublished() (true here).
        $this->assertTrue($product->shouldBeSearchable());
    }
}
