<?php

declare(strict_types=1);

namespace Tests\Integrations\Zoho;

use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Integrations\Zoho\Workflows\ZohoLeadActivity;
use Kanvas\Workflow\Models\StoredWorkflow;
use Tests\TestCase;

final class LeadActivityTest extends TestCase
{
    public function testLeadCreationWorkflow(): void
    {
        //use factory
        $lead = Lead::first();

        $activity = new ZohoLeadActivity(
            0,
            now()->toDateTimeString(),
            StoredWorkflow::make(),
            []
        );

        $result = $activity->execute($lead);
        $this->assertSame('processing lead ' . $lead->id, $result);
    }
}
