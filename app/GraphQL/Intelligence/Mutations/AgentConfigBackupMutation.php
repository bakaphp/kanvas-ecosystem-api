<?php

declare(strict_types=1);

namespace App\GraphQL\Intelligence\Mutations;

use Kanvas\Apps\Models\Apps;
use Kanvas\Intelligence\Agents\Actions\CreateAgentConfigBackupAction;
use Kanvas\Intelligence\Agents\Actions\RestoreAgentFromConfigBackupAction;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentConfigBackup;

class AgentConfigBackupMutation
{
    public function create(mixed $root, array $request): AgentConfigBackup
    {
        $app = app(Apps::class);
        $agent = Agent::fromApp()
            ->fromCompany(auth()->user()->getCurrentCompany())
            ->findOrFail((int) $request['agent_id']);

        return (new CreateAgentConfigBackupAction(
            agent: $agent,
            app: $app,
            notes: $request['notes'] ?? null,
        ))->execute();
    }

    public function restore(mixed $root, array $request): Agent
    {
        $backup = AgentConfigBackup::where('id', (int) $request['backup_id'])
            ->where('apps_id', app(Apps::class)->getId())
            ->firstOrFail();

        return (new RestoreAgentFromConfigBackupAction($backup))->execute();
    }
}
