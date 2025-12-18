<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\DealerSocket;

use Kanvas\Connectors\DealerSocket\Enums\CustomFieldEnum;
use Kanvas\Connectors\DealerSocket\Services\DealerSocketConfigurationService;
use Kanvas\Connectors\DealerSocket\Services\DealerSocketLeadService;
use Kanvas\Connectors\DealerSocket\Services\DealerSocketWorkNoteService;
use Kanvas\Guild\Leads\Models\Lead;
use Tests\Connectors\Traits\HasDealerSocketConfiguration;
use Tests\TestCase;

final class WorkNoteTest extends TestCase
{
    use HasDealerSocketConfiguration;

    public function testCreateWorkNote()
    {
        $lead = Lead::first();

        $company = $lead->company;
        $app = $lead->app;
        $region = $company->defaultRegion;

        $this->setupDealerSocketConfiguration($company, $app);

        $eventId = $lead->get(
            CustomFieldEnum::DEALER_SOCKET_LEAD_ID->value
        );

        if (! $eventId) {
            $leadService = new DealerSocketLeadService($app, $company);
            $response = $leadService->saveLead($lead);
            $this->assertArrayHasKey('leadId', $response);
        }

        $note = 'New Npte - ' . now()->format('H:i:s');
        $workNoteService = new DealerSocketWorkNoteService($app, $company);
        $response = $workNoteService->addNoteToLead($lead, $note);

        $this->assertNotEmpty($response['success']);
    }
}
