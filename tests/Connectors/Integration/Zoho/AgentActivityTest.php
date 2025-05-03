<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Zoho;

use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Zoho\Enums\CustomFieldEnum;
use Kanvas\Connectors\Zoho\Handlers\ZohoHandler;
use Kanvas\Connectors\Zoho\Workflows\ZohoAgentActivity;
use Kanvas\Connectors\Zoho\ZohoService;
use Kanvas\Guild\Agents\Models\Agent;
use Kanvas\Guild\Enums\FlagEnum;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\Models\StoredWorkflow;
use Tests\Connectors\Traits\HasIntegrationCompany;
use Tests\TestCase;

final class AgentActivityTest extends TestCase
{
    use HasIntegrationCompany;
    
    public function testLeadCreationWorkflow(): void
    {
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
       
        $this->setIntegration(
            $app,
            IntegrationsEnum::ZOHO,
            ZohoHandler::class,
            $company,
            $lead->user
        );

        $activity = new ZohoAgentActivity(
            0,
            now()->toDateTimeString(),
            StoredWorkflow::make(),
            []
        );

        $faker = \Faker\Factory::create();

        $user = $lead->user()->firstOrFail();
        $user->firstname = 'TEST Kanvas';
        $user->lastname = $faker->lastName();
        $user->saveOrFail();

        $result = $activity->execute($user, $app, ['company' => $company]);

        $zohoService = new ZohoService($app, $company);
        $agent = Agent::getByMemberNumber($result['member_id'], $company);
        $zohoService->deleteAgent($agent);

        $this->assertIsArray($result);
        $this->assertNotEmpty($result['zohoId']);
        $this->assertNotEmpty($result['member_id']);
        $this->assertNotEmpty($result['users_id']);
        $this->assertNotEmpty($result['companies_id']);
    }
}
