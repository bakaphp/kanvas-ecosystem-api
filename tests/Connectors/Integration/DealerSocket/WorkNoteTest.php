<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\DealerSocket;

use Kanvas\Connectors\DealerSocket\Services\DealerSocketConfigurationService;
use Kanvas\Connectors\DealerSocket\Services\DealerSocketLeadService;
use Kanvas\Connectors\DealerSocket\Services\DealerSocketWorkNoteService;
use Kanvas\Guild\Leads\Models\Lead;
use Tests\Connectors\Traits\HasDealerSockerConfiguration;
use Tests\TestCase;

final class WorkNoteTest extends TestCase
{
    use HasDealerSockerConfiguration;

    public function testCreateWorkNote()
    {
        $lead = Lead::first();

        $company = $lead->company;
        $app = $lead->app;
        $region = $company->defaultRegion;

        $this->setupDealerSocketConfiguration($company, $app, $region);

        $eventId = $lead->get(
            DealerSocketConfigurationService::getLeadIdKey($lead, $region)
        );

        if (! $eventId) {
            $leadService = new DealerSocketLeadService($app, $company, $region);
            $response = $leadService->saveLead($lead);
            $this->assertArrayHasKey('leadId', $response);
        }

        $note = 'New Npte - ' . now()->format('H:i:s');
        $workNoteService = new DealerSocketWorkNoteService($app, $company, $region);
        $response = $workNoteService->addNoteToLead($lead, $note);

        $this->assertNotEmpty($response['success']);
    }
}
