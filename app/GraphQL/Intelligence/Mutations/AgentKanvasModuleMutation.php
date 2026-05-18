<?php

declare(strict_types=1);

namespace App\GraphQL\Intelligence\Mutations;

use Kanvas\Apps\Models\Apps;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentKanvasModule;
use Kanvas\KanvasModules\Models\KanvasModule;

// The canonical path is observer-driven (tool grant/revoke triggers
// ReconcileAgentKanvasModulesAction). These mutations are app-owner overrides.
class AgentKanvasModuleMutation
{
    public function set(mixed $root, array $req): AgentKanvasModule
    {
        $app = app(Apps::class);
        $agent = Agent::getById((int) $req['agent_id'], $app);
        $module = KanvasModule::query()
            ->where('id', (int) $req['kanvas_module_id'])
            ->where('is_deleted', 0)
            ->firstOrFail();

        return AgentKanvasModule::updateOrCreate(
            [
                'agent_id' => $agent->getId(),
                'kanvas_modules_id' => $module->id,
            ],
            [
                'config' => $req['config'] ?? null,
                'is_active' => $req['is_active'] ?? true,
                'is_deleted' => false,
            ],
        );
    }

    public function remove(mixed $root, array $req): bool
    {
        $app = app(Apps::class);
        $agent = Agent::getById((int) $req['agent_id'], $app);

        $deleted = AgentKanvasModule::query()
            ->where('agent_id', $agent->getId())
            ->where('kanvas_modules_id', (int) $req['kanvas_module_id'])
            ->update(['is_active' => false, 'is_deleted' => true]);

        return $deleted > 0;
    }
}
