<?php

declare(strict_types=1);

namespace Kanvas\Connectors\OpenClaw\Actions;

use Kanvas\Connectors\OpenClaw\Services\WorkspaceFileBuilder;
use Kanvas\Connectors\OpenClaw\SshClient;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentDeployment;

/**
 * Sync workspace files (SOUL.md, AGENTS.md, IDENTITY.md, etc.) to a running
 * deployment and restart the container so OpenClaw picks up changes.
 */
class SyncAgentWorkspaceAction
{
    public function __construct(
        protected Agent $agent,
        protected AgentDeployment $deployment,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function execute(): array
    {
        $client = SshClient::fromMachine($this->deployment->machine);

        try {
            $workspaceDir = $this->deployment->home_directory . '/.openclaw/workspace';
            $systemUser = $this->deployment->system_user;

            $files = WorkspaceFileBuilder::buildAll($this->agent);

            foreach ($files as $filename => $content) {
                $client->writeFileAsUser(
                    $workspaceDir . '/' . $filename,
                    $content,
                    $systemUser,
                );
            }

            $client->exec(
                'sudo chown -R 1000:1000 ' . escapeshellarg($workspaceDir)
            );

            $openclawDir = $this->deployment->home_directory . '/.openclaw';
            $client->exec(
                'sudo -u ' . escapeshellarg($systemUser)
                . ' bash -c ' . escapeshellarg('cd ' . $openclawDir . ' && docker compose restart 2>&1')
            );

            return [
                'success' => true,
                'message' => 'Workspace synced and container restarted',
                'deployment_id' => $this->deployment->getId(),
                'container' => $this->deployment->container_name,
                'files_synced' => array_keys($files),
            ];
        } finally {
            $client->disconnect();
        }
    }
}
