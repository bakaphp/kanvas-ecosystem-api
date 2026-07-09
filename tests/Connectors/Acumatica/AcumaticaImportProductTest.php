<?php

declare(strict_types=1);

namespace Tests\Connectors\Acumatica;

use Kanvas\Connectors\Acumatica\DataTransferObject\AcumaticaImportProduct;
use Kanvas\Connectors\Acumatica\Enums\CustomFieldEnum;
use Tests\TestCase;

class AcumaticaImportProductTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private function sampleRow(array $overrides = []): array
    {
        return array_merge([
            'InventoryID' => 704,
            'InventoryCD' => 'COOLER-X-BLACK',
            'Descr' => 'ACME Liquid Cooler X - Black',
            'ItemStatus' => 'AC',
            'BasePrice' => 279.99,
            'BaseItemWeight' => 3.5,
            'NoteID' => 'EC2DFB9E-6EEF-E511-9439-023BECF28BA7',
            'ImageUrl' => 'https://cdn.example.test/cooler-x-black.png',
            'UsrUPC' => '000000000123',
            'StkItem' => true,
        ], $overrides);
    }

    public function testMapsCoreProductFields(): void
    {
        $importer = AcumaticaImportProduct::fromRow($this->sampleRow());

        $this->assertSame('COOLER-X-BLACK', $importer->sku);
        $this->assertSame('ACME Liquid Cooler X - Black', $importer->name);
        $this->assertSame('cooler-x-black', $importer->slug);
        $this->assertTrue($importer->isPublished);
        $this->assertSame('acumatica', $importer->source);
        $this->assertSame('704', $importer->sourceId);
        $this->assertSame('000000000123', $importer->upc);
        $this->assertSame(3.5, $importer->weight);
    }

    public function testBuildsSingleVariantWithPrice(): void
    {
        $importer = AcumaticaImportProduct::fromRow($this->sampleRow());

        $this->assertCount(1, $importer->variants);
        $variant = $importer->variants[0];
        $this->assertSame('COOLER-X-BLACK', $variant['sku']);
        $this->assertSame(279.99, $variant['price']);
        $this->assertSame(0, $variant['quantity']); // stock synced separately
        $this->assertSame('704', $variant['source_id']);
    }

    public function testCarriesAcumaticaIdentifiersAsCustomFieldsAndImage(): void
    {
        $importer = AcumaticaImportProduct::fromRow($this->sampleRow());

        $names = array_column($importer->customFields, 'name');
        $this->assertContains(CustomFieldEnum::PRODUCT_ID->value, $names);
        $this->assertContains(CustomFieldEnum::NOTE_ID->value, $names);

        $this->assertCount(1, $importer->files);
        $this->assertSame('https://cdn.example.test/cooler-x-black.png', $importer->files[0]['url']);
    }

    public function testInactiveStatusIsUnpublished(): void
    {
        $importer = AcumaticaImportProduct::fromRow($this->sampleRow(['ItemStatus' => 'IN']));

        $this->assertFalse($importer->isPublished);
    }

    public function testFallsBackToSkuWhenDescriptionEmptyAndOmitsWeightWhenZero(): void
    {
        $importer = AcumaticaImportProduct::fromRow($this->sampleRow([
            'Descr' => '',
            'BaseItemWeight' => 0,
            'BaseWeight' => 0,
            'ImageUrl' => null,
        ]));

        $this->assertSame('COOLER-X-BLACK', $importer->name);
        $this->assertNull($importer->weight);
        $this->assertSame([], $importer->files);
    }
}
