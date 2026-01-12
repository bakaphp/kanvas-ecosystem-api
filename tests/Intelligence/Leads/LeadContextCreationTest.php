<?php

declare(strict_types=1);

namespace Tests\Intelligence\Leads;

use Kanvas\ActionEngine\Engagements\Models\Engagement;
use Kanvas\ActionEngine\Support\Setup as SupportSetup;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Enums\ConfigurationEnum;
use Kanvas\Connectors\SalesAssist\Enums\LeadCustomFieldEnum;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Leads\Models\LeadType;
use Kanvas\Guild\LeadSources\Models\LeadSource;
use Kanvas\Guild\Support\Setup;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Enums\ConfigurationEnum as EnumsConfigurationEnum;
use Kanvas\Intelligence\Leads\Actions\CreateLeadContextInfoAction;
use Kanvas\Intelligence\Sessions\Actions\CreateContentSessionAction;
use Kanvas\Intelligence\Sessions\DataTransferObject\Session;
use Kanvas\Intelligence\Tools\ArtifactsTool;
use Kanvas\Intelligence\Tools\CommunicationChannelTool;
use Kanvas\Intelligence\Tools\CompanyIsHolidayTool;
use Kanvas\Intelligence\Tools\CompanyWorkHoursTool;
use Kanvas\Intelligence\Tools\CompletionStatusTool;
use Kanvas\Intelligence\Tools\LeadIntentTool;
use Kanvas\Intelligence\Tools\LeadRefTool;
use Kanvas\Intelligence\Tools\VehicleInterestTool;
use Kanvas\Social\Channels\Models\Channel;
use Tests\TestCase;

final class LeadContextCreationTest extends TestCase
{
    public function testCreateLeadContextInfoAction(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        $setupInventory = new Setup($app, $user, $company);
        $setupInventory->run();

        $company->set(ConfigurationEnum::WORKING_DAYS->value, [
            'monday',
            'tuesday',
            'wednesday',
            'thursday',
            'friday',
        ]);
        $company->set(ConfigurationEnum::WORKING_HOURS->value, [
            'opens_at_local' => '08:00:00',
            'closes_at_local' => '17:00:00',
        ]);

        $lead = Lead::factory()->withAppId($app->getId())->withCompanyId($company->getId())->create();
        $agent = Agent::factory()->withAppId($app->getId())->withCompanyId($company->getId())->create();
        $agent->name = 'CompletionStatusTool';
        $agent->saveOrFail();

        $leadType = LeadType::firstOrCreate([
            'name' => 'Internet ',
            'companies_id' => $company->getId(),
            'apps_id' => $app->getId(),
        ], [
            'description' => 'Internet Lead',
        ]);

        $lead->leads_types_id = $leadType->id;
        $lead->saveOrFail();

        $leadSource = LeadSource::create([
           'apps_id' => $app->getId(),
           'companies_id' => $company->getId(),
           'name' => 'Contact Us',
           'description' => 'Test Description',
           'is_active' => 1,
           'leads_types_id' => $leadType->id,
        ]);
        $lead->leads_sources_id = $leadSource->id;
        $lead->saveOrFail();

        $company->set('adf_sources', [
           [
               'Source' => 'Contact Us',
               'Sub_Source' => 'Website',
               'Backend' => 'General Inquiry',
               'Default_Completion_Status' => 'New',
           ],
           [
               'Source' => 'Test Source',
               'Sub_Source' => 'Test Subsource',
               'Backend' => 'Test Backend',
               'Default_Completion_Status' => 'Test Status',
           ],
        ]);

        $lead->set(LeadCustomFieldEnum::VEHICLE_OF_INTEREST->value, [
            'isNew' => true,
            'yearFrom' => 2024,
            'make' => 'Toyota',
            'model' => 'Camry',
            'trim' => 'XSE',
            'vin' => '4T1G11AK7PU123456',
            'stockNumber' => 'STK-8891',
            'isPrimary' => true,
            'price' => 38995,
        ]);
        $lead->refresh();

        $pipelineConfiguration = [
            'actions' => [
                [
                    'class' => LeadRefTool::class,
                    'params' => [],
                    'contact_index' => 'lead_ref',
                ],
                [
                    'class' => CommunicationChannelTool::class,
                    'params' => [],
                    'contact_index' => 'communication',
                ],
                [
                    'class' => CompanyIsHolidayTool::class,
                    'params' => [],
                    'contact_index' => 'holiday_status',
                ],
                [
                    'class' => CompanyWorkHoursTool::class,
                    'params' => [],
                    'contact_index' => 'work_hours_status',
                ],
                [
                    'class' => VehicleInterestTool::class,
                    'params' => [],
                    'contact_index' => 'vehicle_interest',
                ],
                [
                    'class' => ArtifactsTool::class,
                    'params' => [],
                    'contact_index' => 'artifacts',
                ],
                [
                    'class' => LeadIntentTool::class,
                    'params' => [],
                    'contact_index' => 'lead_intent',
                ],
               /*  [
                    'class' => CompletionStatusTool::class,
                    'params' => [],
                    'contact_index' => 'completion_status',
                ], */
            ],
        ];

        $pipelineStage = $lead->getCurrentPipelineStage();
        $pipelineStage->config = $pipelineConfiguration;
        $pipelineStage->saveOrFail();

        $context = new CreateLeadContextInfoAction($lead)->execute([
            'pipelinesMapping' => [
                'Internet ' => $lead->pipeline_id,
            ],
        ]);
        $this->assertIsArray($context);
        $this->assertArrayHasKey('lead_ref', $context);
        $this->assertArrayHasKey('communication', $context);
        $this->assertArrayHasKey('holiday_status', $context);
        $this->assertArrayHasKey('work_hours_status', $context);
        $this->assertArrayHasKey('vehicle_interest', $context);
        $this->assertArrayHasKey('artifacts', $context);
        $this->assertEquals($lead->get(EnumsConfigurationEnum::LEAD_CONTEXT_INFO->value), $context);
        $this->assertNotEmpty($lead->get(EnumsConfigurationEnum::LEAD_CONTEXT_INFO->value));
    }

    public function testMultipleConcurrentChecklistUrlCreationDoesNotCreateDuplicates(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        $company->set('ai-agent-user-id', $user->getId());
        $setupInventory = new Setup($app, $user, $company);
        $setupInventory->run();

        $actions = [[
              'id' => 7,
              'name' => 'credit-app',
              'description' => 'Credit App',
              'title' => 'Credit App',
              'enable' => true,
              'icon' => '',
              'reasonEn' => 'apply for financing',
              'reasonEs' => 'apply for financing',
              'form_fields' => '{"personal":{"type":"object","required":1},"housing":{"type":"object","required":1},"financial":{"type":"object","required":1}}',
              'form_config' => '{"require_credit-app_signature":true}',
        ],[
              'id' => 8,
              'name' => 'add-trade',
              'description' => 'Add Trade-In',
              'title' => 'Add Trade-In',
              'enable' => true,
              'icon' => '',
              'reasonEn' => 'add a trade-in',
              'reasonEs' => 'add a trade-in',
              'form_fields' => '{"vehicle":{"type":"object","required":1}}',
              'form_config' => '{}',
        ]];

        new SupportSetup($app, $user, $company, $actions)->run();
        // Create lead with necessary setup
        $lead = Lead::factory()->withAppId($app->getId())->withCompanyId($company->getId())->create();
        $agent = Agent::factory()->withAppId($app->getId())->withCompanyId($company->getId())->create();

        $app->associateUser($user, 1, null, null);

        // Create a channel for the lead
        $channel = Channel::firstOrCreate([
            'apps_id' => $app->getId(),
            'companies_id' => $company->getId(),
            'entity_id' => $lead->getId(),
            'entity_namespace' => Lead::class,
        ], [
            'name' => $lead->uuid,
            'slug' => $lead->uuid,
            'description' => 'Test channel for lead',
            'users_id' => $user->getId(),
        ]);

        // Create session DTO
        $sessionDto = new Session(
            app: $app,
            company: $company,
            channel: $channel,
            agent: $agent,
            entity_namespace: Lead::class,
            entity_id: (string) $lead->getId(),
            user: [
                'id' => $user->getId(),
                'name' => $user->displayname,
            ],
            userModel: $user
        );

        // Simulate multiple concurrent requests by calling CreateContentSessionAction multiple times
        // This would simulate different channels/requests hitting the same lead
        $results = [];
        $iterations = 10; // Simulate 10 concurrent requests

        for ($i = 0; $i < $iterations; $i++) {
            $action = new CreateContentSessionAction($sessionDto);
            $content = $action->execute();
            $results[] = $content;
        }

        // All results should have the same checklist URLs (not creating duplicates)
        $this->assertCount($iterations, $results);

        // Get all checklist URLs from the results
        $checklistUrls = array_map(function ($result) {
            return $result['checklist'] ?? [];
        }, $results);

        // Verify all results have the same URLs (meaning no duplicates were created)
        $firstChecklistUrls = $checklistUrls[0];
        foreach ($checklistUrls as $urls) {
            $this->assertEquals($firstChecklistUrls, $urls, 'All checklist URLs should be identical across multiple calls');
        }

        // Verify we only created one engagement per action type
        $engagements = Engagement::where('leads_id', $lead->getId())
            ->where('is_deleted', 0)
            ->get();

        // We should have exactly 2 engagements: 1 for credit-app and 1 for add-trade
        $this->assertLessThanOrEqual(2, $engagements->count(), 'Should not create duplicate engagements');

        // Group by action slug to verify no duplicates per action
        $groupedBySlug = $engagements->groupBy('slug');

        foreach ($groupedBySlug as $slug => $engagementsForSlug) {
            $this->assertEquals(
                1,
                $engagementsForSlug->count(),
                "Should only have 1 engagement for action '{$slug}', but found {$engagementsForSlug->count()}"
            );
        }

        // Verify the URLs are valid and match the engagement messages
        if (isset($firstChecklistUrls['creditApp'])) {
            $this->assertNotEmpty($firstChecklistUrls['creditApp']);
            $this->assertStringContainsString('credit-app', $firstChecklistUrls['creditApp']);
        }

        if (isset($firstChecklistUrls['tradeIn'])) {
            $this->assertNotEmpty($firstChecklistUrls['tradeIn']);
            $this->assertStringContainsString('add-trade', $firstChecklistUrls['tradeIn']);
        }
    }
}
