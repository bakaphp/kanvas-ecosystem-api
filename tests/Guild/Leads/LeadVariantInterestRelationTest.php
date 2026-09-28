<?php

declare(strict_types=1);

namespace Tests\Guild\Leads;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Leads\Models\LeadVariantInterest;
use Kanvas\Inventory\Variants\Models\Variants;
use Tests\TestCase;

class LeadVariantInterestRelationTest extends TestCase
{
    public function testLeadHasManyVariantInterests(): void
    {
        $relation = new Lead()->variantInterests();

        $this->assertInstanceOf(HasMany::class, $relation);
        $this->assertInstanceOf(LeadVariantInterest::class, $relation->getRelated());
        $this->assertSame('leads_id', $relation->getForeignKeyName());
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
