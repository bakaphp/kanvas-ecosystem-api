<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Actions;

use Illuminate\Support\Facades\DB;
use Kanvas\Intelligence\Agents\DataTransferObject\AgentLlmConfig as AgentLlmConfigData;
use Kanvas\Intelligence\Agents\Models\AgentLlmConfig;

class UpdateAgentLlmConfigAction
{
    public function __construct(
        protected readonly AgentLlmConfig $config,
        protected readonly AgentLlmConfigData $data,
    ) {
    }

    public function execute(): AgentLlmConfig
    {
        return DB::connection('intelligence')->transaction(function () {
            $this->config->name = $this->data->name;
            $this->config->provider = $this->data->provider->value;
            $this->config->base_uri = $this->data->base_uri;
            $this->config->api_key = $this->data->api_key;
            $this->config->model = $this->data->model;
            $this->config->config = $this->data->config;
            $this->config->is_active = $this->data->is_active;
            $this->config->saveOrFail();

            return $this->config;
        });
    }
}
