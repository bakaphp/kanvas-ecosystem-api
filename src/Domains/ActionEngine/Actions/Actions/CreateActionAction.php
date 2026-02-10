<?php

declare(strict_types=1);

namespace Kanvas\ActionEngine\Actions\Actions;

use Baka\Contracts\AppInterface;
use Baka\Users\Contracts\UserInterface;
use Illuminate\Support\Facades\DB;
use Kanvas\ActionEngine\Actions\DataTransferObject\Action as ActionData;
use Kanvas\ActionEngine\Actions\Models\Action;
use Kanvas\ActionEngine\Pipelines\Actions\CreatePipelineAction;

class CreateActionAction
{
    public function __construct(
        protected readonly ActionData $data,
        protected readonly UserInterface $user,
        protected readonly AppInterface $app,
    ) {
    }

    public function execute(): Action
    {
        return DB::connection('action_engine')->transaction(function () {
            $pipeline = new CreatePipelineAction(
                name: $this->data->name,
                user: $this->user,
                app: $this->app,
            )->execute();

            $action = new Action();
            $action->apps_id = $this->app->getId();
            $action->companies_id = 0;
            $action->users_id = $this->user->getId();
            $action->name = $this->data->name;
            $action->description = $this->data->description;
            $action->icon = $this->data->icon;
            $action->form_fields = $this->data->form_fields;
            $action->form_config = $this->data->form_config;
            $action->is_active = $this->data->is_active;
            $action->collects_info = $this->data->collects_info;
            $action->is_published = $this->data->is_published;
            $action->pipelines_id = $this->data->pipelines_id ?? $pipeline->getId();

            if ($this->data->parent_id !== null) {
                $action->parent_id = $this->data->parent_id;
            }

            $action->saveOrFail();

            return $action;
        });
    }
}
