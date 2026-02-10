<?php

declare(strict_types=1);

namespace Kanvas\ActionEngine\Actions\Actions;

use Baka\Contracts\AppInterface;
use Baka\Users\Contracts\UserInterface;
use Illuminate\Support\Facades\DB;
use Kanvas\ActionEngine\Actions\DataTransferObject\CompanyAction as CompanyActionData;
use Kanvas\ActionEngine\Actions\Models\CompanyAction;
use Kanvas\ActionEngine\Pipelines\Actions\CreatePipelineAction;
use Kanvas\Companies\Models\Companies;

class CreateCompanyActionAction
{
    public function __construct(
        protected readonly CompanyActionData $data,
        protected readonly UserInterface $user,
        protected readonly AppInterface $app,
        protected readonly Companies $company,
    ) {
    }

    public function execute(): CompanyAction
    {
        return DB::connection('action_engine')->transaction(function () {
            $action = $this->data->action;

            $pipeline = new CreatePipelineAction(
                name: $this->data->name,
                user: $this->user,
                app: $this->app,
                companiesId: $this->company->getId(),
            )->execute();

            $companyAction = new CompanyAction();
            $companyAction->apps_id = $this->app->getId();
            $companyAction->companies_id = $this->company->getId();
            $companyAction->companies_branches_id = $this->user->getCurrentBranch()->getId();
            $companyAction->actions_id = $action->getId();
            $companyAction->users_id = $this->user->getId();
            $companyAction->pipelines_id = $pipeline->getId();
            $companyAction->name = $this->data->name;
            $companyAction->description = $this->data->description ?? $action->description;
            $companyAction->form_config = $this->data->form_config ?? $action->form_config;
            $companyAction->is_active = $this->data->is_active ?? false;
            $companyAction->is_published = $this->data->is_published ?? false;

            if ($this->data->config !== null) {
                $companyAction->config = $this->data->config;
            }

            if ($this->data->parent_id !== null) {
                $companyAction->parent_id = $this->data->parent_id;
            }

            $companyAction->saveOrFail();

            return $companyAction;
        });
    }
}
