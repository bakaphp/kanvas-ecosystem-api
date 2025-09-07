<?php

declare(strict_types=1);

namespace Kanvas\ActionEngine\Support;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Users\Contracts\UserInterface;
use Illuminate\Support\Facades\DB;
use Kanvas\ActionEngine\Actions\Models\Action;
use Kanvas\ActionEngine\Actions\Models\CompanyAction;
use Kanvas\ActionEngine\Engagements\Models\Engagement;
use Kanvas\ActionEngine\Pipelines\Models\Pipeline;
use Kanvas\ActionEngine\Pipelines\Models\PipelineStage;
use Kanvas\ActionEngine\Pipelines\Models\PipelineStageMessage;
use Kanvas\ActionEngine\Tasks\Models\TaskEngagementItem;
use Kanvas\ActionEngine\Tasks\Models\TaskList;
use Kanvas\ActionEngine\Tasks\Models\TaskListItem;
use Kanvas\Companies\Models\Companies as CompanyModel;
use Kanvas\Companies\Models\CompaniesBranches;
use Kanvas\Enums\AppEnums;
use Kanvas\Social\MessagesTypes\Models\MessageType;
use Kanvas\SystemModules\Actions\CreateInCurrentAppAction;
use RuntimeException;

class Setup
{
    protected CompaniesBranches $branch;
    protected CreateInCurrentAppAction $createSystemModule;
    protected string $defaultIcon = '<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" width="48" height="48" viewBox="0 0 48 48"><defs><clipPath id="clip-Icon-Refer_Solid"><rect width="48" height="48"/></clipPath></defs><g id="Icon-Refer_Solid" clip-path="url(#clip-Icon-Refer_Solid)"><rect width="48" height="48" fill="#fff"/><path id="Path_699" data-name="Path 699" d="M24.533,16.847H46.527a5.315,5.315,0,0,0,2.972-.613,2.076,2.076,0,0,0,.854-1.8,7.407,7.407,0,0,0-1.031-3.576,12.638,12.638,0,0,0-2.963-3.5A15.585,15.585,0,0,0,41.688,4.7,17.524,17.524,0,0,0,35.53,3.676,17.524,17.524,0,0,0,29.372,4.7,15.585,15.585,0,0,0,24.7,7.354a12.638,12.638,0,0,0-2.963,3.5,7.407,7.407,0,0,0-1.031,3.576,2.076,2.076,0,0,0,.854,1.8A5.315,5.315,0,0,0,24.533,16.847Zm11-16.31A6.389,6.389,0,0,0,39.05-.494a7.572,7.572,0,0,0,2.573-2.8,8.142,8.142,0,0,0,.966-3.975,7.728,7.728,0,0,0-.966-3.854,7.42,7.42,0,0,0-2.582-2.721,6.533,6.533,0,0,0-3.511-1,6.443,6.443,0,0,0-3.5,1.012,7.577,7.577,0,0,0-2.582,2.74,7.722,7.722,0,0,0-.975,3.864,8,8,0,0,0,.975,3.938,7.648,7.648,0,0,0,2.582,2.8A6.358,6.358,0,0,0,35.53.537ZM5.549,16.847H18.793a3.957,3.957,0,0,1-.7-2.4,8.393,8.393,0,0,1,.613-2.972,13.4,13.4,0,0,1,4.245-5.647,14.437,14.437,0,0,0-3.381-1.588,13.788,13.788,0,0,0-4.31-.622,14.1,14.1,0,0,0-5.35.966A13.122,13.122,0,0,0,5.846,7.131a11.651,11.651,0,0,0-2.591,3.446,8.4,8.4,0,0,0-.9,3.669,2.5,2.5,0,0,0,.724,1.923A3.584,3.584,0,0,0,5.549,16.847ZM15.264.927A5.586,5.586,0,0,0,18.319.036,6.556,6.556,0,0,0,20.567-2.4a7.068,7.068,0,0,0,.845-3.455,6.731,6.731,0,0,0-.845-3.372,6.431,6.431,0,0,0-2.248-2.359,5.718,5.718,0,0,0-3.056-.864,5.641,5.641,0,0,0-3.037.873A6.56,6.56,0,0,0,9.97-9.2a6.724,6.724,0,0,0-.854,3.381A6.977,6.977,0,0,0,9.96-2.389,6.576,6.576,0,0,0,12.208.036,5.586,5.586,0,0,0,15.264.927Z" transform="translate(-2.354 22.999)" fill="#707070"/></g></svg>';

    /**
     * Constructor.
     */
    public function __construct(
        protected AppInterface $app,
        protected UserInterface $user,
        protected CompanyInterface $company,
        protected array $actions
    ) {
        if (empty($actions)) {
            throw new RuntimeException('Actions array cannot be empty');
        }

        $this->company = $company;

        // Ensure company has a default branch
        // Cast company to concrete model to access defaultBranch property
        /** @var CompanyModel $company */
        $company = $this->company;

        $this->branch = $company->defaultBranch;
        if (! $this->branch) {
            // Company should already have a default branch created by observer
            $branch = $company->branches()->where('is_default', 1)->first();
            if ($branch instanceof CompaniesBranches) {
                $this->branch = $branch;
            }
            if (! $this->branch) {
                throw new RuntimeException('Company must have a default branch');
            }
        }
        $this->createSystemModule = new CreateInCurrentAppAction($this->app);
    }

    /**
     * Setup all the action engine components for this company.
     */
    public function run(): bool
    {
        return DB::transaction(function () {
            // Create system modules
            $this->createSystemModules();

            // Import industry actions
            $actions = $this->importIndustryActions();

            // Create company actions and pipelines
            $this->createCompanyActionsAndPipelines($actions);

            // Create message types for each action
            $this->createMessageTypes($actions);

            // Add dummy engagements for each company action
            $this->addDummyEngagements();

            return true;
        });
    }

    /**
     * Create required system modules.
     */
    protected function createSystemModules(): void
    {
        $this->createSystemModule->execute(Action::class);
        $this->createSystemModule->execute(CompanyAction::class);
        $this->createSystemModule->execute(Pipeline::class);
        $this->createSystemModule->execute(PipelineStage::class);
        $this->createSystemModule->execute(PipelineStageMessage::class);
        $this->createSystemModule->execute(Engagement::class);
        $this->createSystemModule->execute(TaskList::class);
        $this->createSystemModule->execute(TaskListItem::class);
        $this->createSystemModule->execute(TaskEngagementItem::class);
        $this->createSystemModule->execute(CompaniesBranches::class);
    }

    /**
     * Import industry actions from template.
     */
    protected function importIndustryActions(): array
    {
        $industryActions = $this->getIndustryActionsTemplate();
        $createdActions = [];

        foreach ($industryActions as $industryAction) {
            $action = Action::firstOrCreate(
                [
                    'slug' => $industryAction['name'],
                    'companies_id' => AppEnums::GLOBAL_COMPANY_ID->getValue(),
                    'is_deleted' => 0,
                ],
                [
                    'apps_id' => $this->app->getId(),
                    'companies_id' => AppEnums::GLOBAL_COMPANY_ID->getValue(),
                    'users_id' => 0,
                    'pipelines_id' => 0, // Will be set later
                    'name' => $industryAction['title'] ?? 'No Name',
                    'description' => $industryAction['description'] ?? '',
                    'form_fields' => $industryAction['form_fields'] ?? '',
                    'form_config' => $industryAction['form_config'] ?? '',
                    'is_active' => $industryAction['enable'] ?? 0,
                    'is_published' => $industryAction['enable'] ?? 0,
                ]
            );

            $createdActions[] = $action;
        }

        return $createdActions;
    }

    /**
     * Create company actions and their pipelines.
     */
    protected function createCompanyActionsAndPipelines(array $actions): void
    {
        $pipelineTemplates = $this->getPipelineTemplates();
        foreach ($actions as $action) {
            $companyAction = CompanyAction::firstOrCreate(
                [
                    'actions_id' => $action->getId(),
                    'companies_id' => $this->company->getId(),
                    'is_deleted' => 0,
                ],
                [
                    'apps_id' => $this->app->getId(),
                    'companies_id' => $this->company->getId(),
                    'companies_branches_id' => $this->branch->getId(),
                    'users_id' => $this->user->getId(),
                    'pipelines_id' => 0, // Will be set later
                    'name' => $action->name,
                    'description' => $action->description,
                    'form_config' => $action->form_config,
                    'is_active' => 0, // Start inactive
                    'is_published' => $action->is_published,
                ]
            );

            // Create pipeline if template exists
            $pipelineTemplate = $pipelineTemplates[$action->slug] ?? $pipelineTemplates['default'];
            if ($pipelineTemplate) {
                $pipeline = $this->createPipeline($action, $pipelineTemplate);

                // Update company action with pipeline
                $companyAction->pipelines_id = $pipeline->getId();
                $companyAction->saveOrFail();
            }
        }
    }

    /**
     * Create a pipeline with stages.
     */
    protected function createPipeline(Action $action, array $stageTemplates): Pipeline
    {
        // Create pipeline directly
        $pipeline = Pipeline::firstOrCreate([
            'companies_id' => $this->company->getId(),
            'apps_id' => $this->app->getId(),
            'users_id' => $this->user->getId(),
            'name' => $action->name,
            'slug' => $action->slug,
        ], [
            'weight' => count($stageTemplates),
        ]);

        // Create stages
        foreach ($stageTemplates as $stageTemplate) {
            $stage = PipelineStage::firstOrCreate([
                'pipelines_id' => $pipeline->getId(),
                'name' => $stageTemplate['name'],
                'slug' => $stageTemplate['slug'],
            ], [
                 'weight' => $stageTemplate['weight'],
                'has_rotting_days' => false,
                'rotting_days' => 0,
            ]);

            // Create stage messages
            PipelineStageMessage::updateOrCreate(
                [
                    'pipelines_stages_id' => $stage->getId(),
                ],
                [
                    'pipelines_stages_id' => $stage->getId(),
                    'message' => $stageTemplate['message'],
                    'message_notification' => $stageTemplate['notification'],
                ]
            );
        }

        return $pipeline;
    }

    /**
     * Create message types for each action.
     */
    protected function createMessageTypes(array $actions): void
    {
        foreach ($actions as $action) {
            MessageType::firstOrCreate(
                [
                    'apps_id' => $this->app->getId(),
                    'languages_id' => 1,
                    'verb' => $action->slug,
                ],
                [
                    'apps_id' => $this->app->getId(),
                    'verb' => $action->slug,
                    'name' => $action->name,
                    'languages_id' => 1,
                ]
            );
        }
    }

    /**
     * Add dummy engagements for testing/initialization.
     */
    protected function addDummyEngagements(): void
    {
        $dummyEntityUuid = '00000000-0000-0000-0000-000000000000'; // Standard dummy UUID

        $companyActions = CompanyAction::where('companies_id', $this->company->getId())
            ->where('is_deleted', 0)
            ->get();

        foreach ($companyActions as $companyAction) {
            if (! $companyAction->pipeline || ! $companyAction->action) {
                continue;
            }

            $firstStage = $companyAction->pipeline->stages()->orderBy('weight')->first();
            if (! $firstStage) {
                continue;
            }

            // Check if dummy engagement already exists
            $existingEngagement = Engagement::where('apps_id', $this->app->getId())
                ->where('companies_actions_id', $companyAction->getId())
                ->where('pipelines_stages_id', $firstStage->getId())
                ->where('slug', $companyAction->action->slug)
                ->where('entity_uuid', $dummyEntityUuid)
                ->where('is_deleted', 0)
                ->exists();

            if (! $existingEngagement) {
                $engagement = new Engagement();
                $engagement->apps_id = $this->app->getId();
                $engagement->companies_id = $this->company->getId();
                $engagement->companies_actions_id = $companyAction->getId();
                $engagement->users_id = $this->user->getId();
                $engagement->pipelines_stages_id = $firstStage->getId();
                $engagement->message_id = 0;
                $engagement->leads_id = 0;
                $engagement->entity_uuid = $dummyEntityUuid;
                $engagement->slug = $companyAction->action->slug;
                $engagement->saveOrFail();
            }
        }
    }

    /**
     * Get industry actions template.
     */
    protected function getIndustryActionsTemplate(): array
    {
        return $this->actions;
    }

    /**
     * Get pipeline stage templates.
     */
    protected function getPipelineTemplates(): array
    {
        $viewStage = [
            [
                'name' => 'shared',
                'slug' => 'sent',
                'weight' => 1,
                'message' => "user ~ ' shared ' ~ postTitle ~' to ' ~ contact",
                'notification' => "user ~ ' shared the ' ~  postTitle ~ ' with ' ~ contact  ~ ' ' ~ shareDate ~ '.'",
            ],
            [
                'name' => 'read',
                'slug' => 'opened',
                'weight' => 2,
                'message' => "contact ~ ' read ' ~ postTitle ~' that '~ user ~' shared'",
                'notification' => "contact ~ ' read the ' ~  postTitle ~ ' ' ~ shareDate ~ '.'",
            ],
        ];

        $downloadStage = [
            [
                'name' => 'shared',
                'slug' => 'sent',
                'weight' => 1,
                'message' => "user ~ ' shared ' ~ postTitle ~' to ' ~ contact",
                'notification' => "user ~ ' shared the ' ~  postTitle ~ ' with ' ~ contact  ~ ' ' ~ shareDate ~ '.'",
            ],
            [
                'name' => 'read',
                'slug' => 'opened',
                'weight' => 2,
                'message' => "contact ~ ' read ' ~ postTitle ~' that '~ user ~' shared'",
                'notification' => "contact ~ ' read the ' ~  postTitle ~ ' that you shared ' ~ shareDate ~ '.'",
            ],
            [
                'name' => 'saved/downloaded',
                'slug' => 'submitted',
                'weight' => 3,
                'message' => "contact ~ ' saved/downloaded  ' ~ postTitle ~ ' to ' ~ contact",
                'notification' => "contact ~ ' saved/downloaded ' ~ postTitle ~ ' ' ~ shareDate ~ '.'",
            ],
        ];

        $formsStage = [
            [
                'name' => 'shared',
                'slug' => 'sent',
                'weight' => 1,
                'message' => "user ~ ' shared ' ~ postTitle ~' to ' ~ contact",
                'notification' => "user ~ ' shared the ' ~  postTitle ~ ' with ' ~ contact  ~ ' ' ~ shareDate ~ '.'",
            ],
            [
                'name' => 'read',
                'slug' => 'opened',
                'weight' => 2,
                'message' => "contact ~ ' read ' ~ postTitle ~' that '~ user ~' shared'",
                'notification' => "contact ~ ' read the ' ~  postTitle ~ ' that you shared ' ~ shareDate ~ '.'",
            ],
            [
                'name' => 'submitted',
                'slug' => 'submitted',
                'weight' => 3,
                'message' => "contact ~ ' submitted ' ~ postTitle ~' that '~ user ~' shared'",
                'notification' => "contact ~ ' submitted the ' ~  postTitle ~ ' that you shared ' ~ shareDate ~ '.'",
            ],
        ];

        return [
            'dealer-content' => $viewStage,
            'view-vehicle' => $viewStage,
            'trade-walk' => $formsStage,
            'search-hub' => $viewStage,
            'download-vehicle' => $viewStage,
            'get-docs' => $formsStage,
            'needs-analysis' => $formsStage,
            'add-trade' => $formsStage,
            'payoff-form' => $formsStage,
            'validate-sold' => $formsStage,
            'esign-docs' => $formsStage,
            'credit-app' => $formsStage,
            'credit-app-2' => $formsStage,
            'credit-app-3' => $formsStage,
            'credit-app-4' => $formsStage,
            'credit-app-5' => $formsStage,
            'credit-app-6' => $formsStage,
            'credit-app-7' => $formsStage,
            'business-credit-app' => $formsStage,
            'get-referrals' => $formsStage,
            'co-signer' => $formsStage,
            'co-signer-2' => $formsStage,
            'co-signer-3' => $formsStage,
            'default' => $formsStage,
            'co-signer-4' => $formsStage,
            'co-signer-5' => $formsStage,
            'in-store-visit' => $viewStage,
            'cc-auth' => $formsStage,
            'digital-card' => $downloadStage,
            // Add more mappings as needed
        ];
    }
}
