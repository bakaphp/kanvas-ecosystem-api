<?php

declare(strict_types=1);

namespace Tests\Guild;

use Illuminate\Support\Collection;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Leads\Models\LeadVariantInterest;
use Kanvas\Guild\Leads\Services\LeadVariantInterestProjectionService;
use Kanvas\Intelligence\Knowledge\Sources\LeadKnowledgeSource;
use Kanvas\Inventory\Attributes\Models\Attributes;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Inventory\Variants\Models\Variants;
use Kanvas\Inventory\Variants\Models\VariantsAttributes;
use Mockery;
use Tests\TestCase;

class LeadVariantInterestProjectionServiceTest extends TestCase
{
    public function testBuildsGenericActiveVariantProjection(): void
    {
        $lead = $this->leadWithVariantInterest();

        $projection = new LeadVariantInterestProjectionService()->build($lead);

        $this->assertCount(1, $projection['items']);
        $this->assertSame(6544, $projection['items'][0]['variant_id']);
        $this->assertSame('RAV4', $projection['items'][0]['attributes'][0]['value']);
        $this->assertStringContainsString('Toyota RAV4', $projection['search_text']);
        $this->assertStringContainsString('model RAV4', $projection['search_text']);
    }

    public function testLeadTypesenseSchemaIncludesOptionalVariantFields(): void
    {
        $lead = $this->leadWithVariantInterest();
        $searchable = $lead->toSearchableArray();
        $fields = collect($lead->typesenseCollectionSchema()['fields'])->keyBy('name');

        $this->assertCount(1, $searchable['variant_interests']);
        $this->assertNotSame('', $searchable['variant_search_text']);
        $this->assertSame('object[]', $fields['variant_interests']['type']);
        $this->assertTrue($fields['variant_interests']['optional']);
        $this->assertSame('string', $fields['variant_search_text']['type']);
        $this->assertTrue($fields['variant_search_text']['optional']);
        $this->assertStringContainsString('variant_search_text', $lead->searchQueryBy());
    }

    public function testLeadKnowledgeSourceIncludesVariantInterestDocument(): void
    {
        $lead = $this->leadWithVariantInterest();
        $documents = new LeadKnowledgeSource()->build($lead);
        $variantDocument = collect($documents)->firstWhere('metadata.source_type', 'variant-interests');

        $this->assertNotNull($variantDocument);
        $this->assertStringContainsString('Toyota RAV4', $variantDocument->content);
        $this->assertStringContainsString('model: RAV4', $variantDocument->content);
        $this->assertSame(11, $variantDocument->metadata['apps_id']);
        $this->assertSame(22, $variantDocument->metadata['companies_id']);
    }

    private function leadWithVariantInterest(): Lead
    {
        $app = Mockery::mock(Apps::class)->makePartial();
        $app->id = 11;
        $app->shouldReceive('get')->andReturnNull();

        $company = new Companies(['name' => 'Acme']);
        $company->id = 22;
        $product = new Products([
            'apps_id' => 11,
            'companies_id' => 22,
            'name' => 'Toyota RAV4',
            'is_published' => true,
            'is_deleted' => false,
        ]);
        $product->id = 812;

        $attribute = new Attributes([
            'name' => 'model',
            'is_searchable' => true,
        ]);
        $attribute->id = 90;
        $variantAttribute = new VariantsAttributes([
            'products_variants_id' => 6544,
            'attributes_id' => 90,
            'value' => 'RAV4',
            'is_deleted' => false,
        ]);
        $variantAttribute->setRelation('attribute', $attribute);

        $variant = new Variants([
            'apps_id' => 11,
            'companies_id' => 22,
            'products_id' => 812,
            'name' => '2027 Toyota RAV4 XLE',
            'sku' => 'VIN-RAV4',
            'is_published' => true,
            'is_deleted' => false,
        ]);
        $variant->id = 6544;
        $variant->setRelation('product', $product);
        $variant->setRelation('channels', new Collection());
        $variant->setRelation('variantAttributes', new Collection([$variantAttribute]));

        $interest = new LeadVariantInterest([
            'apps_id' => 11,
            'companies_id' => 22,
            'users_id' => 1,
            'leads_id' => 55,
            'variants_id' => 6544,
            'interest_type' => 'primary',
            'price_at_interest' => 29495,
            'is_active' => true,
            'is_deleted' => false,
        ]);
        $interest->id = 1;
        $interest->setRelation('variant', $variant);

        $lead = new Lead([
            'apps_id' => 11,
            'companies_id' => 22,
            'firstname' => 'Ada',
            'lastname' => 'Lovelace',
            'title' => 'RAV4 shopper',
            'status' => 0,
        ]);
        $lead->id = 55;
        $lead->setRelation('app', $app);
        $lead->setRelation('company', $company);
        $lead->setRelation('people', null);
        $lead->setRelation('organization', null);
        $lead->setRelation('socialChannels', new Collection());
        $lead->setRelation('variantInterests', new Collection([$interest]));

        return $lead;
    }
}
