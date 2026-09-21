<?php

declare(strict_types=1);

namespace Tests\Baka\Unit;

use Kanvas\Apps\Models\Apps;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Inventory\Variants\Models\Variants;
use Tests\TestCase;

final class AlgoliaSyncSettingsCommandTest extends TestCase
{
    public function testRejectsAModelWithoutDeclaredSettings(): void
    {
        $this->artisan('kanvas:search:algolia-sync-settings', ['model' => Apps::class])
            ->expectsOutputToContain('is not a model with algoliaIndexSettings()')
            ->assertFailed();
    }

    public function testSkipsAnAppThatDoesNotIndexIntoAlgolia(): void
    {
        $app = app(Apps::class);
        $app->set('products_search_engine', 'typesense');

        try {
            $this->artisan('kanvas:search:algolia-sync-settings', [
                'model' => Products::class,
                '--app' => $app->getId(),
                '--dry-run' => true,
            ])
                ->expectsOutput('0 setting(s) would be applied.')
                ->assertSuccessful();
        } finally {
            $app->del('products_search_engine');
        }
    }

    public function testProductsAndVariantsDeclareSearchableAttributes(): void
    {
        foreach ([new Products(), new Variants()] as $model) {
            $settings = $model->algoliaIndexSettings();

            $this->assertNotEmpty($settings['searchableAttributes']);
            $this->assertSame('name', $settings['searchableAttributes'][0]);
            $this->assertContains('filterOnly(apps_id)', $settings['attributesForFaceting']);
            $this->assertContains('filterOnly(company.id)', $settings['attributesForFaceting']);
        }
    }
}
