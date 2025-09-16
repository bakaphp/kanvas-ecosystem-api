<?php

declare(strict_types=1);

namespace Tests\Intelligence\Leads;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Enums\ConfigurationEnum;
use Kanvas\Connectors\SalesAssist\Enums\LeadCustomFieldEnum;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Leads\Models\LeadType;
use Kanvas\Guild\Support\Setup;
use Kanvas\Intelligence\Enums\ConfigurationEnum as EnumsConfigurationEnum;
use Kanvas\Intelligence\Leads\Actions\CreateLeadContextInfoAction;
use Kanvas\Intelligence\Tools\ArtifactsTool;
use Kanvas\Intelligence\Tools\CommunicationChannelTool;
use Kanvas\Intelligence\Tools\CompanyIsHolidayTool;
use Kanvas\Intelligence\Tools\CompanyWorkHoursTool;
use Kanvas\Intelligence\Tools\CompletionStatusTool;
use Kanvas\Intelligence\Tools\LeadIntentTool;
use Kanvas\Intelligence\Tools\LeadRefTool;
use Kanvas\Intelligence\Tools\VehicleInterestTool;
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

        $leadType = LeadType::firstOrCreate([
            'name' => 'Internet ',
            'companies_id' => $company->getId(),
            'apps_id' => $app->getId(),
        ], [
            'description' => 'Internet Lead',
        ]);

        $lead->leads_types_id = $leadType->id;
        $lead->saveOrFail();
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
                [
                    'class' => CompletionStatusTool::class,
                    'params' => [],
                    'contact_index' => 'completion_status',
                ],
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
}
