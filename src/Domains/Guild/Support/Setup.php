<?php

declare(strict_types=1);

namespace Kanvas\Guild\Support;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Enums\StateEnums;
use Baka\Users\Contracts\UserInterface;
use Kanvas\Guild\Customers\Models\AddressType;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Leads\Models\LeadReceiver;
use Kanvas\Guild\Leads\Models\LeadSource;
use Kanvas\Guild\Leads\Models\LeadStatus;
use Kanvas\Guild\Leads\Models\LeadType;
use Kanvas\Guild\Organizations\Models\Organization;
use Kanvas\Guild\Pipelines\Models\Pipeline;
use Kanvas\Guild\Pipelines\Models\PipelineStage;
use Kanvas\SystemModules\Actions\CreateInCurrentAppAction;
use Kanvas\SystemModules\Repositories\SystemModulesRepository;

class Setup
{
    public array $leadTypes = [
        'Cold' => 'Cold',
        'Warm' => 'Warm',
        'Hot' => 'Hot',
        'IQL' => 'Information Qualified Lead',
        'SRL' => 'Sales-Ready Lead',
        'MQL' => 'Marketing Qualified Leads',
        'SQL' => 'Sales Qualified Lead',
    ];

    public array $leadSources = [
        'Google',
        'Facebook',
        'Twitter',
        'Instagram',
        'LinkedIn',
        'Youtube',
        'Chat',
        'Email',
        'Phone',
        'WalkIn',
    ];

    public array $defaultStages = [
        'New',
        'Qualified',
        'Demo Scheduled',
        'Pending Commitment',
        'In Negotiation',
        'Won',
    ];

    public array $leadStatus = [
        'Active',
        'Sold',
        'Inactive',
        'Complete',
        'Sold',
        'Inactive',
        'Close',
        'Won',
        'Bad',
        'Duplicate',
    ];

    public array $addressType = [
        'Home',
        'PreviousHome',
        'Employer',
        'PreviousEmployer',
        'Other',
    ];

    /**
     * Constructor.
     */
    public function __construct(
        protected AppInterface $app,
        protected UserInterface $user,
        protected CompanyInterface $company
    ) {
    }

    /**
     * Setup all the default inventory data for this current company.
     */
    public function run(): bool
    {
        // $createSystemModule = new CreateInCurrentAppAction($this->app);
        $leadSystemModule = SystemModulesRepository::getByModelName(Lead::class);
        // $createSystemModule->execute(People::class);
        // $createSystemModule->execute(Organization::class);
        // $createSystemModule->execute(Pipeline::class);

        (new CreateSystemModule($this->app))->run();

        foreach ($this->leadTypes as $key => $value) {
            LeadType::firstOrCreate([
                'name' => $key,
                'companies_id' => $this->company->getId(),
                'apps_id' => $this->app->getId(),
            ], [
               'description' => $value,
            ]);
        }

        foreach ($this->leadSources as $key => $value) {
            LeadSource::firstOrCreate([
                'name' => $value,
                'companies_id' => $this->company->getId(),
                'apps_id' => $this->app->getId(),
            ], [
               'description' => $value ?? null,
               'leads_types_id' => null,
            ]);
        }

        foreach ($this->addressType as $key => $value) {
            AddressType::firstOrCreate([
                'name' => $value,
                'companies_id' => 0,
                'apps_id' => $this->app->getId(),
            ]);
        }

        $defaultPipelineName = 'Default Leads';
        $defaultPipeline = Pipeline::firstOrCreate([
            'name' => $defaultPipelineName,
            'companies_id' => $this->company->getId(),
            'system_modules_id' => $leadSystemModule->getId(),
        ], [
            'users_id' => $this->user->getId(),
            'is_default' => StateEnums::YES->getValue(),
            'weight' => 0,
        ]);

        $weight = 1;
        foreach ($this->defaultStages as $key => $value) {
            PipelineStage::firstOrCreate([
                'name' => $value,
                'pipelines_id' => $defaultPipeline->getId(),
            ], [
                'weight' => $weight++,
            ]);
        }

        LeadReceiver::firstOrCreate([
            'companies_branches_id' => $this->company->defaultBranch()->firstOrFail()->getId(),
            'companies_id' => $this->company->getId(),
            'apps_id' => $this->app->getId(),
            'is_default' => StateEnums::YES->getValue(),
        ], [
            'users_id' => $this->user->getId(),
            'agents_id' => $this->user->getId(),
            'name' => 'Default Receiver',
            'rotations_id' => 0,
            'source_name' => 'Default Receiver',
        ]);

        foreach ($this->leadStatus as $key => $value) {
            LeadStatus::firstOrCreate([
                'name' => $value,
            ]);
        }

        return LeadType::fromCompany($this->company)->count() == count($this->leadTypes) &&
            LeadReceiver::fromCompany($this->company)->count() > 0 &&
            LeadSource::fromApp($this->app)->fromCompany($this->company)->count() == count($this->leadSources) &&
            Pipeline::fromCompany($this->company)->count() > 0 &&
            LeadStatus::count() > 0 &&
            PipelineStage::where('pipelines_id', $defaultPipeline->getId())->count() == count($this->defaultStages);
    }
}
