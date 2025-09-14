<?php

declare(strict_types=1);

namespace Tests\Intelligence;

use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Leads\Models\LeadType;
use Kanvas\Guild\Support\Setup;
use Kanvas\Intelligence\Agents\Actions\CreateLeadContextInfoAction;
use Kanvas\Intelligence\Tools\CompanyIsHolidayTool;
use Kanvas\Intelligence\Tools\CompanyWorkHoursTool;
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

        $lead->refresh();

        $pipelineConfiguration = [
            'actions' => [
                [
                    'class' => CompanyWorkHoursTool::class,
                    'params' => [],
                    'contact_index' => 'company-work-hours',
                ],
                [
                    'class' => CompanyIsHolidayTool::class,
                    'params' => [],
                    'contact_index' => 'company-holidays',
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

        //print_r($context);

        $this->assertIsArray($context);
        $this->assertArrayHasKey('company-work-hours', $context);
        $this->assertArrayHasKey('company-holidays', $context);
    }
}
