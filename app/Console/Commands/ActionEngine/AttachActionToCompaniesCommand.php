<?php

declare(strict_types=1);

namespace App\Console\Commands\ActionEngine;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Kanvas\ActionEngine\Actions\Actions\AttachActionToCompanyBranchesAction;
use Kanvas\ActionEngine\Actions\Models\Action;
use Kanvas\ActionEngine\Actions\Models\CompanyAction;
use Kanvas\ActionEngine\Pipelines\Actions\CreatePipelineAction;
use Kanvas\ActionEngine\Pipelines\DataTransferObject\Pipeline as PipelineData;
use Kanvas\ActionEngine\Pipelines\Models\Pipeline;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Enums\AppEnums;
use Kanvas\Users\Models\UserCompanyApps;
use Throwable;

use function Laravel\Prompts\error;
use function Laravel\Prompts\info;
use function Laravel\Prompts\table;
use function Laravel\Prompts\warning;

class AttachActionToCompaniesCommand extends Command
{
    use KanvasJobsTrait;

    protected $signature = 'kanvas-action-engine:attach-action
        {app_id : The app whose companies should get the action}
        {slug : The action slug to attach}
        {--company= : Only this company id}
        {--branch= : Only this branch id}
        {--create-action : Create the global actions row when the slug does not exist yet}
        {--inactive : Create the companies_actions rows with is_active = 0}
        {--dry-run : List what would be written without making changes}';

    protected $description = 'Attach an Action Engine action slug to every branch of every company of an app, creating the per-company pipeline it needs.';

    public function handle(): int
    {
        /** @var Apps $app */
        $app = Apps::getById((int) $this->argument('app_id'));
        $this->overwriteAppService($app);

        $slug = (string) $this->argument('slug');
        $dryRun = (bool) $this->option('dry-run');

        $action = $this->resolveAction($app, $slug, $dryRun);

        if ($action === null) {
            return self::FAILURE;
        }

        $rows = [];
        $skipped = 0;
        $failed = 0;
        $companies = 0;

        foreach ($this->companies($app) as $company) {
            $companies++;

            try {
                $branches = $company->branches()
                    ->where('is_deleted', 0)
                    ->when(
                        $this->option('branch'),
                        fn ($query, $branchId) => $query->where('id', (int) $branchId)
                    )
                    ->get();

                if ($branches->isEmpty()) {
                    warning('Company ' . $company->getId() . ' has no live branch, skipping.');
                    $skipped++;

                    continue;
                }

                $existingBranchIds = CompanyAction::where('actions_id', $action->getId())
                    ->where('apps_id', $app->getId())
                    ->where('companies_id', $company->getId())
                    ->where('is_deleted', 0)
                    ->pluck('companies_branches_id')
                    ->map(fn ($id): int => (int) $id)
                    ->all();

                $pipeline = $this->resolvePipeline($app, $company, $action, $dryRun);

                if ($dryRun) {
                    foreach ($branches as $branch) {
                        $rows[] = [
                            $company->getId(),
                            $company->name,
                            $branch->getId(),
                            $branch->name,
                            '-',
                            $pipeline?->getId() ?? 'would create',
                            in_array($branch->getId(), $existingBranchIds, true) ? 'exists' : 'would create',
                        ];
                    }

                    continue;
                }

                $companyActions = new AttachActionToCompanyBranchesAction(
                    action: $action,
                    pipeline: $pipeline,
                    branches: $branches,
                    isActive: ! $this->option('inactive'),
                )->execute();

                foreach ($companyActions as $companyAction) {
                    $branchId = (int) $companyAction->companies_branches_id;

                    $rows[] = [
                        $company->getId(),
                        $company->name,
                        $branchId,
                        $branches->firstWhere('id', $branchId)?->name,
                        $companyAction->getId(),
                        $companyAction->pipelines_id,
                        in_array($branchId, $existingBranchIds, true) ? 'existing' : 'created',
                    ];
                }
            } catch (Throwable $exception) {
                report($exception);
                warning('Company ' . $company->getId() . ' failed: ' . $exception->getMessage());
                $failed++;
            }
        }

        if ($rows !== []) {
            table(
                ['company_id', 'company', 'branch_id', 'branch', 'company_action_id', 'pipeline_id', 'state'],
                $rows
            );
        }

        info(sprintf(
            '%s %d row(s) across %d company(ies); %d skipped, %d failed.',
            $dryRun ? 'Would attach' : 'Attached',
            count($rows),
            $companies,
            $skipped,
            $failed
        ));

        return self::SUCCESS;
    }

    private function resolveAction(Apps $app, string $slug, bool $dryRun): ?Action
    {
        $matches = Action::where('slug', $slug)->where('is_deleted', 0)->get();

        if ($matches->count() > 1) {
            error('Ambiguous slug: ' . $matches->count() . ' global action rows match "' . $slug . '".');

            return null;
        }

        if ($matches->count() === 1) {
            return $matches->first();
        }

        if (! $this->option('create-action')) {
            error('Action "' . $slug . '" not found. Pass --create-action to create the global row.');

            return null;
        }

        if ($dryRun) {
            info('Would create the global action row for "' . $slug . '".');

            return null;
        }

        return Action::firstOrCreate(
            [
                'slug' => $slug,
                'companies_id' => AppEnums::GLOBAL_COMPANY_ID->getValue(),
                'is_deleted' => 0,
            ],
            [
                'apps_id' => $app->getId(),
                'users_id' => AppEnums::GLOBAL_USER_ID->getValue(),
                'pipelines_id' => 0,
                'name' => ucfirst(str_replace('-', ' ', $slug)),
                'description' => ucfirst(str_replace('-', ' ', $slug)),
                'is_active' => 1,
                'is_published' => 1,
            ]
        );
    }

    /**
     * The pipeline is per (app, company) and its slug must equal the action slug — that is what
     * CreateEngagementAction looks up before resolving the submitted stage.
     */
    private function resolvePipeline(
        Apps $app,
        Companies $company,
        Action $action,
        bool $dryRun
    ): ?Pipeline {
        $pipeline = Pipeline::query()
            ->where('slug', $action->slug)
            ->where('companies_id', $company->getId())
            ->where('apps_id', $app->getId())
            ->first();

        if ($pipeline !== null || $dryRun) {
            return $pipeline;
        }

        return new CreatePipelineAction(
            new PipelineData(name: $action->name, slug: $action->slug),
            $company->user,
            $app,
            $company->getId(),
        )->execute();
    }

    /**
     * @return iterable<Companies>
     */
    private function companies(Apps $app): iterable
    {
        $companyIds = UserCompanyApps::where('apps_id', $app->getId())
            ->when(
                $this->option('company'),
                fn ($query, $companyId) => $query->where('companies_id', (int) $companyId)
            )
            ->distinct()
            ->pluck('companies_id');

        foreach ($companyIds as $companyId) {
            $company = Companies::query()->notDeleted()->find((int) $companyId);

            if ($company !== null) {
                yield $company;
            }
        }
    }
}
