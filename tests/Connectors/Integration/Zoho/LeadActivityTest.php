<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Zoho;

use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Zoho\Enums\CustomFieldEnum;
use Kanvas\Connectors\Zoho\Workflows\ZohoLeadActivity;
use Kanvas\Guild\Enums\FlagEnum;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Workflow\Models\StoredWorkflow;
use Tests\TestCase;

final class LeadActivityTest extends TestCase
{
    public function testLeadCreationWorkflow(): void
    {
        //use factory
        $lead = Lead::first();
        $lead->description = 'this is a test lead from github actions';
        $lead->saveOrFail();

        $company = $lead->company()->firstOrFail();
        $app = app(Apps::class);

        $company->set(FlagEnum::APP_GLOBAL_ZOHO->value, 1);
        $app->set(CustomFieldEnum::CLIENT_ID->value, env('TEST_ZOHO_CLIENT_ID'));
        $app->set(CustomFieldEnum::CLIENT_SECRET->value, env('TEST_ZOHO_CLIENT_SECRET'));
        $app->set(CustomFieldEnum::REFRESH_TOKEN->value, env('TEST_ZOHO_CLIENT_REFRESH_TOKEN'));

        $activity = new ZohoLeadActivity(
            0,
            now()->toDateTimeString(),
            StoredWorkflow::make(),
            []
        );

        $result = $activity->execute($app, $lead);
        $this->assertArrayHasKey('zohoLeadId', $result);
        $this->assertArrayHasKey('zohoRequest', $result);
        $this->assertArrayHasKey('leadId', $result);
    }
}
