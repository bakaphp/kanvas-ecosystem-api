<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Zoho;

use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Zoho\Enums\CustomFieldEnum;
use Kanvas\Connectors\Zoho\Workflows\ZohoAgentActivity;
use Kanvas\Connectors\Zoho\Workflows\ZohoLeadActivity;
use Kanvas\CustomFields\Models\CustomFields;
use Kanvas\Guild\Enums\FlagEnum;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Workflow\Models\StoredWorkflow;
use Tests\TestCase;

final class AgentActivityTest extends TestCase
{
    public function testLeadCreationWorkflow(): void
    {
        //use factory
        $lead = Lead::first();
        $lead->description = 'this is a test lead from github actions';
        $lead->saveOrFail();

        $lead->del('ZOHO_LEAD_ID');
        $company = $lead->company()->firstOrFail();
        $app = app(Apps::class);

        $app->set(FlagEnum::APP_GLOBAL_ZOHO->value, 1);
        $app->set(CustomFieldEnum::CLIENT_ID->value, getenv('TEST_ZOHO_CLIENT_ID'));
        $app->set(CustomFieldEnum::CLIENT_SECRET->value, getenv('TEST_ZOHO_CLIENT_SECRET'));
        $app->set(CustomFieldEnum::REFRESH_TOKEN->value, getenv('TEST_ZOHO_CLIENT_REFRESH_TOKEN'));
        $company->set(CustomFieldEnum::ZOHO_HAS_AGENTS_MODULE->value, 1);

        $activity = new ZohoAgentActivity(
            0,
            now()->toDateTimeString(),
            StoredWorkflow::make(),
            []
        );

        $result = $activity->execute($lead->user()->firstOrFail(), $app, ['company' => $company]);
       print_r($result); die();
        $this->assertIsArray($result);
    }
}
