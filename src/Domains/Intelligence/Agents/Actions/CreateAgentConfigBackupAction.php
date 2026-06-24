<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Actions;

use Kanvas\Apps\Models\Apps;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentConfigBackup;
use Illuminate\Support\Facades\Storage;
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
            $path = $service->upload($this->agent, $this->app, $data);

            $backup->update([
                'status' => 'completed',
                'file_path' => $path,
                'file_size_bytes' => Storage::size($path),
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
}
