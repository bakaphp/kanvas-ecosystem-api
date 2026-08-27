<?php

declare(strict_types=1);

namespace Tests\Intelligence\Knowledge;

use Illuminate\Support\Facades\Bus;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Leads\Models\LeadVariantInterest;
use Kanvas\Guild\Leads\Observers\LeadVariantInterestObserver;
use Kanvas\Intelligence\Knowledge\Jobs\ReindexLeadVariantInterestJob;
use Kanvas\Intelligence\Knowledge\Jobs\ReindexVariantInterestLeadsJob;
use Kanvas\Intelligence\Knowledge\Listeners\QueueVariantInterestReindexListener;
use Kanvas\Inventory\Variants\Events\VariantSearchDocumentChanged;
use Tests\TestCase;

class VariantInterestIndexingTest extends TestCase
{
    public function testInterestChangeQueuesLeadReindex(): void
    {
        Bus::fake();

        $lead = new Lead([
            'apps_id' => 11,
            'companies_id' => 22,
        ]);
        $lead->id = 55;
        $interest = new LeadVariantInterest([
            'apps_id' => 11,
            'companies_id' => 22,
            'leads_id' => 55,
        ]);
        $interest->setRelation('lead', $lead);

        new LeadVariantInterestObserver()->saved($interest);

        Bus::assertDispatched(
            ReindexLeadVariantInterestJob::class,
            fn (ReindexLeadVariantInterestJob $job): bool => $job->lead->id === 55
                && $job->lead->appId === 11
                && $job->lead->companyId === 22,
        );
    }

    public function testVariantChangeQueuesRelatedLeadReindex(): void
    {
        Bus::fake();

        new QueueVariantInterestReindexListener()->handle(
            new VariantSearchDocumentChanged(6544, 11, 22)
        );

        Bus::assertDispatched(
            ReindexVariantInterestLeadsJob::class,
            fn (ReindexVariantInterestLeadsJob $job): bool => $job->variantId === 6544
                && $job->appId === 11
                && $job->companyId === 22,
        );
    }
}
