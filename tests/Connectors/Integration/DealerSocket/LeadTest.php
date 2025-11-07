<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\DealerSocket;

use Kanvas\Connectors\DealerSocket\Services\DealerSocketLeadService;
use Kanvas\Guild\Leads\Models\Lead;
use Tests\Connectors\Traits\HasDealerSockerConfiguration;
use Tests\TestCase;

final class LeadTest extends TestCase
{
    use HasDealerSockerConfiguration;

    public function testCreateLead()
    {
        $lead = Lead::first();

        $company = $lead->company;
        $app = $lead->app;
        $region = $company->defaultRegion;

        $this->setupDealerSocketConfiguration($company, $app, $region);

        $leadService = new DealerSocketLeadService($app, $company, $region);

        $response = $leadService->saveLead($lead);

        $this->assertArrayHasKey('leadId', $response);
    }

    public function testUpdateLead()
    {
        $lead = Lead::first();

        $company = $lead->company;
        $app = $lead->app;
        $region = $company->defaultRegion;

        $this->setupDealerSocketConfiguration($company, $app, $region);

        $leadService = new DealerSocketLeadService($app, $company, $region);

        $lead->title = 'TEST - ' . now()->format('H:i:s');
        $lead->description = 'Probando actualización';
        $lead->save();
        $response = $leadService->updateLead($lead);

        $this->assertNotEmpty($response['success']);
    }
}
