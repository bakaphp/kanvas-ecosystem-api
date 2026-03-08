<?php

declare(strict_types=1);

namespace Kanvas\Connectors\OpenClaw\Actions;

use Baka\Contracts\CompanyInterface;
use Kanvas\Connectors\OpenClaw\Enums\CustomFieldEnum;
use Kanvas\Connectors\OpenClaw\Services\WorkspaceFileBuilder;
use Kanvas\Connectors\OpenClaw\SshClient;
use Kanvas\Intelligence\Agents\Models\Agent;
use Throwable;

class UpdateAgentDeploymentAction
{
    public function __construct(
        protected Agent $agent,
        protected CompanyInterface $company,
    ) {
    }

    public function execute(): Agent
    {
        $client = new SshClient($this->company);
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
