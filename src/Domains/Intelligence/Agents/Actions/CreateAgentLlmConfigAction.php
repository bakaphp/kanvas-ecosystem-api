<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Actions;

use Illuminate\Support\Facades\DB;
use Kanvas\Intelligence\Agents\DataTransferObject\AgentLlmConfig as AgentLlmConfigData;
use Kanvas\Intelligence\Agents\Models\AgentLlmConfig;

class CreateAgentLlmConfigAction
{
    public function __construct(
        protected readonly AgentLlmConfigData $data,
    ) {
    }

    public function execute(): AgentLlmConfig
    {
        return DB::connection('intelligence')->transaction(function () {
            $config = new AgentLlmConfig();
            $config->apps_id = $this->data->app->getId();
            $config->companies_id = $this->data->company->getId();
            $config->users_id = $this->data->user->getId();
            $config->name = $this->data->name;
            $config->provider = $this->data->provider->value;
            $config->base_uri = $this->data->base_uri;
            $config->api_key = $this->data->api_key;
            $config->model = $this->data->model;
            $config->config = $this->data->config;
            $config->is_active = $this->data->is_active;
            $config->saveOrFail();

            return $config;
        });
    }
}
