<?php

declare(strict_types=1);

namespace Tests\Connectors\Zoho;

use Kanvas\Connectors\Zoho\DataTransferObject\ZohoLead;
use Kanvas\Guild\Leads\Models\Lead;
use Tests\TestCase;

final class ConversionTest extends TestCase
{
    public function testEcosystemLeadToZoho(): void
    {
        //use factory
        $lead = Lead::factory()->create();

        $zohoLead = ZohoLead::fromLead($lead);

        print_r($zohoLead);
        die();
    }
}
