<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Actions;

use Illuminate\Support\Facades\Storage;
use Kanvas\Apps\Models\Apps;
use Kanvas\Intelligence\AgentRuntime\Providers\AgentRuntimeProviderFactory;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentBackup;
use Kanvas\Intelligence\Agents\Models\AgentConfigBackup;
use Kanvas\Intelligence\Agents\Services\AgentConfigBackupService;
use Throwable;

class CreateAgentConfigBackupAction
{
    public function __construct(
        protected Agent $agent,
        protected Apps $app,
        protected ?string $notes = null,
    ) {
    }

    public function execute(): AgentConfigBackup
    {
        $backup = new AgentConfigBackup();
        $backup->apps_id = $this->app->getId();
        $backup->companies_id = $this->agent->companies_id;
        $backup->agents_id = $this->agent->getId();
        $backup->status = 'pending';
        $backup->notes = $this->notes;
        $backup->saveOrFail();

        try {
            $service = new AgentConfigBackupService();
            $data = $service->serialize($this->agent);
            $workspaceBackup = $this->ensureFreshWorkspaceBackup($service);
            $path = $service->upload($this->agent, $this->app, $data, $workspaceBackup);

            $backup->update([
                'status' => 'completed',
                'file_path' => $path,
                'file_size_bytes' => Storage::disk('agent-config-backups')->size($path),
                'completed_at' => now(),
            ]);
        } catch (Throwable $e) {
            $backup->update([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
            ]);

            throw $e;
        }

        return $backup->fresh();
    }

    private function ensureFreshWorkspaceBackup(AgentConfigBackupService $service): ?AgentBackup
    {
        $runningDeployment = $this->agent->deployments()
            ->where('status', 'running')
            ->where('is_deleted', 0)
            ->first();

        if ($runningDeployment !== null) {
            try {
                $agentBackup = new AgentBackup();
                $agentBackup->apps_id = $this->app->getId();
                $agentBackup->companies_id = $this->agent->companies_id;
                $agentBackup->agent_deployment_id = $runningDeployment->getId();
                $agentBackup->status = 'pending';
                $agentBackup->notes = 'pre-config-backup snapshot';
                $agentBackup->saveOrFail();

                return AgentRuntimeProviderFactory::forDeployment($runningDeployment)
                    ->createWorkspaceBackupNow($runningDeployment, $agentBackup);
            } catch (Throwable $e) {
                report($e);
            }
        }

        // Container not running or SSH failed — fall back to latest persisted backup.
        return $service->findLatestWorkspaceBackup($this->agent);
    }
}
