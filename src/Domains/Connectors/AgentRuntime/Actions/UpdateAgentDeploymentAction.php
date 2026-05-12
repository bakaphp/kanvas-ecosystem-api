<?php

declare(strict_types=1);

namespace Kanvas\Connectors\AgentRuntime\Actions;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Kanvas\Connectors\AgentRuntime\Enums\CustomFieldEnum;
use Kanvas\Connectors\AgentRuntime\SshClient;
use Kanvas\Intelligence\AgentRuntime\Services\WorkspaceFileBuilder;
use Kanvas\Intelligence\Agents\Models\Agent;
use Throwable;

class UpdateAgentDeploymentAction
{
    public function __construct(
        protected Agent $agent,
        protected AppInterface $app,
        protected CompanyInterface $company,
    ) {
    }

    public function execute(): Agent
    {
        $client = new SshClient($this->app, $this->company);
        $workspacePath = $this->agent->get(CustomFieldEnum::OPENCLAW_WORKSPACE_PATH->value);

        if (empty($workspacePath)) {
            $workspacePath = $client->getWorkspacePath($this->agent->uuid);
        }

        try {
            $files = WorkspaceFileBuilder::buildAll($this->agent);
            foreach ($files as $filename => $content) {
                $client->writeFile($workspacePath . '/' . $filename, $content);
            }

            $this->agent->update(['deployment_status' => 'deployed']);
        } catch (Throwable $e) {
            $this->agent->update(['deployment_status' => 'failed']);

            throw $e;
        } finally {
            $client->disconnect();
        }

        return $this->agent;
    }
}
