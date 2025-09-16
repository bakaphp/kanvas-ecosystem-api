<?php

declare(strict_types=1);

namespace App\Console\Commands\Intelligence;

use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Enums\ConfigurationEnum;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\SalesAssist\Enums\LeadCustomFieldEnum;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Workflow\Enums\WorkflowEnum;

class CreateFakeLeadContextCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'kanvas:create-fake-lead-context {app_id} {company_id} {lead_type_id} {pipeline_stage_id}';

    public function handle(): void
    {
        $appId = $this->argument('app_id');
        $companyId = $this->argument('company_id');
        $leadTypeId = $this->argument('lead_type_id');
        $pipelineStageId = $this->argument('pipeline_stage_id');
        $company = Companies::getById((int) $companyId);
        $app = Apps::getById((int) $appId);
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
        $lead = Lead::factory()
                ->withAppId($app->getId())
                ->withCompanyId($company->getId())
                ->create();
        $lead->leads_types_id = $leadTypeId;
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
        $lead->fireWorkflow(WorkflowEnum::FAKE_CONTEXT);
    }
}
