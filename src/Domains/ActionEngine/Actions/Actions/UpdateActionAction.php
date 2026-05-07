<?php

declare(strict_types=1);

namespace Kanvas\ActionEngine\Actions\Actions;

use Illuminate\Support\Facades\DB;
use Kanvas\ActionEngine\Actions\DataTransferObject\Action as ActionData;
use Kanvas\ActionEngine\Actions\Models\Action;

class UpdateActionAction
{
    public function __construct(
        protected readonly Action $action,
        protected readonly ActionData $data,
    ) {
    }

    public function execute(): Action
    {
        return DB::connection('action_engine')->transaction(function () {
            $this->action->name = $this->data->name;
            $this->action->description = $this->data->description;
            $this->action->icon = $this->data->icon;
            $this->action->form_fields = $this->data->form_fields;
            $this->action->form_config = $this->data->form_config;
            $this->action->is_active = $this->data->is_active;
            $this->action->collects_info = $this->data->collects_info;
            $this->action->is_published = $this->data->is_published;

            if ($this->data->pipelines_id !== null) {
                $this->action->pipelines_id = $this->data->pipelines_id;
            }

            if ($this->data->parent_id !== null) {
                $this->action->parent_id = $this->data->parent_id;
            }

            $this->action->saveOrFail();

            return $this->action;
        });
    }
}
