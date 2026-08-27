<?php

declare(strict_types=1);

namespace Tests\Guild;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Leads\Models\LeadVariantInterest;
use Kanvas\Inventory\Variants\Models\Variants;
use Tests\TestCase;

class LeadVariantInterestRelationTest extends TestCase
{
    public function testLeadAndVariantInterestRelationsAreConfigured(): void
    {
        $leadRelation = new Lead()->variantInterests();
        $variantRelation = new Variants()->leadInterests();

        $this->assertInstanceOf(HasMany::class, $leadRelation);
        $this->assertInstanceOf(LeadVariantInterest::class, $leadRelation->getRelated());
        $this->assertSame('leads_id', $leadRelation->getForeignKeyName());
        $this->assertInstanceOf(HasMany::class, $variantRelation);
        $this->assertInstanceOf(LeadVariantInterest::class, $variantRelation->getRelated());
        $this->assertSame('variants_id', $variantRelation->getForeignKeyName());
    }

    public function testInterestBelongsToLeadAndVariant(): void
    {
        $interest = new LeadVariantInterest();
        $leadRelation = $interest->lead();
        $variantRelation = $interest->variant();

        $this->assertInstanceOf(BelongsTo::class, $leadRelation);
        $this->assertInstanceOf(Lead::class, $leadRelation->getRelated());
        $this->assertSame('leads_id', $leadRelation->getForeignKeyName());
        $this->assertInstanceOf(BelongsTo::class, $variantRelation);
        $this->assertInstanceOf(Variants::class, $variantRelation->getRelated());
        $this->assertSame('variants_id', $variantRelation->getForeignKeyName());
    }
}
