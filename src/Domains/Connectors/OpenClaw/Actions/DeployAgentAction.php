<?php

declare(strict_types=1);

namespace Kanvas\Connectors\OpenClaw\Actions;

use Baka\Contracts\CompanyInterface;
use Kanvas\Connectors\OpenClaw\Enums\CustomFieldEnum;
use Kanvas\Connectors\OpenClaw\Services\WorkspaceFileBuilder;
use Kanvas\Connectors\OpenClaw\SshClient;
use Kanvas\Intelligence\Agents\Models\Agent;
use Throwable;

class DeployAgentAction
{
    public function __construct(
        protected Agent $agent,
        protected CompanyInterface $company,
    ) {
    }

    public function execute(): Agent
    {
        $client = new SshClient($this->company);
        $agentId = $this->agent->uuid;
        $workspacePath = $client->getWorkspacePath($agentId);

        try {
            $client->exec(
                'openclaw agents add ' . escapeshellarg($agentId)
                . ' --workspace ' . escapeshellarg($workspacePath)
            );

            $files = WorkspaceFileBuilder::buildAll($this->agent);
            foreach ($files as $filename => $content) {
                $client->writeFile($workspacePath . '/' . $filename, $content);
            }

            $this->bindChannels($client, $agentId);

            $this->agent->update(['deployment_status' => 'deployed']);
            $this->agent->set(CustomFieldEnum::OPENCLAW_AGENT_ID->value, $agentId);
            $this->agent->set(CustomFieldEnum::OPENCLAW_WORKSPACE_PATH->value, $workspacePath);
        } catch (Throwable $e) {
            $this->agent->update(['deployment_status' => 'failed']);

            throw $e;
        } finally {
            $client->disconnect();
        }

        return $this->agent;
    }

    protected function bindChannels(SshClient $client, string $agentId): void
    {
        $channels = $this->agent->communicationChannels;

        if ($channels->isEmpty()) {
            return;
        }

        $binds = [];
        foreach ($channels as $channel) {
            $binds[] = '--bind ' . escapeshellarg(strtolower($channel->name));
        }

        $client->exec(
            'openclaw agents bind --agent ' . escapeshellarg($agentId) . ' ' . implode(' ', $binds)
        );
    }
}
