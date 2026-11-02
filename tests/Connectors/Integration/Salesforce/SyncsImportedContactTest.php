<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Salesforce;

use Illuminate\Database\Eloquent\Model;
use Kanvas\Connectors\Salesforce\Actions\Concerns\SyncsImportedContact;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Inventory\Variants\Models\Variants;
use Tests\TestCase;

final class SyncsImportedContactTest extends TestCase
{
    public function testRelationshipTagSuffixDisambiguatesRecordsWithTheSameNumericId(): void
    {
        $harness = new class () {
            use SyncsImportedContact;

            public function suffixFor(Model $record): string
            {
                return $this->relationshipTagSuffix($record);
            }
        };

        // Products and Variants are separate auto-incrementing tables, so a Products row and a
        // Variants row with the same id are an everyday occurrence, not an edge case. Before this
        // fix, both suffixed on the bare id ("#23"), so a project-contact re-sync for product #23
        // would match and delete an unrelated location-contact tag for variant #23.
        $product = new Products();
        $product->id = 23;

        $variant = new Variants();
        $variant->id = 23;

        $this->assertNotSame($harness->suffixFor($product), $harness->suffixFor($variant));
        $this->assertStringContainsString('Products:23', $harness->suffixFor($product));
        $this->assertStringContainsString('Variants:23', $harness->suffixFor($variant));
    }
}
