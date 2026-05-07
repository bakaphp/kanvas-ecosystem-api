<?php

declare(strict_types=1);

namespace Kanvas\ActionEngine\Actions\Actions;

use Illuminate\Support\Facades\DB;
use Kanvas\ActionEngine\Actions\DataTransferObject\CompanyAction as CompanyActionData;
use Kanvas\ActionEngine\Actions\Models\CompanyAction;

class UpdateCompanyActionAction
{
    public function __construct(
        protected readonly CompanyAction $companyAction,
        protected readonly CompanyActionData $data,
    ) {
    }

    public function execute(): CompanyAction
    {
        return DB::connection('action_engine')->transaction(function () {
            $this->companyAction->name = $this->data->name;

            if ($this->data->description !== null) {
                $this->companyAction->description = $this->data->description;
            }

            if ($this->data->form_config !== null) {
                $this->companyAction->form_config = $this->data->form_config;
            }

            if ($this->data->config !== null) {
                $this->companyAction->config = $this->data->config;
            }

            if ($this->data->is_active !== null) {
                $this->companyAction->is_active = $this->data->is_active;
            }

            if ($this->data->is_published !== null) {
                $this->companyAction->is_published = $this->data->is_published;
            }

            if ($this->data->weight !== null) {
                $this->companyAction->weight = $this->data->weight;
            }

            if ($this->data->parent_id !== null) {
                $this->companyAction->parent_id = $this->data->parent_id;
            }

            if ($this->data->status !== null) {
                $this->companyAction->status = $this->data->status;
            }

            $this->companyAction->saveOrFail();

            return $this->companyAction;
        });
    }
}
