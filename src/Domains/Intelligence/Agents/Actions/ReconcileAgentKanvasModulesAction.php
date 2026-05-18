<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Actions;

use Illuminate\Support\Facades\DB;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentKanvasModule;
use Kanvas\NervousSystem\Capability\Models\Tool;

/**
 * Tool removal flips rows to is_active=false rather than deleting them, so
 * the user doesn't lose per-module config if the tool comes back. Hard
 * delete is reserved for removeAgentKanvasModule (app-owner mutation).
 */
class ReconcileAgentKanvasModulesAction
{
    public function __construct(
        protected readonly Agent $agent,
    ) {
    }

    /**
     * @return array{added: int, kept: int, deactivated: int}
     */
    public function execute(): array
    {
        $desiredModuleIds = $this->desiredModuleIds();

        $existing = AgentKanvasModule::query()
            ->where('agent_id', $this->agent->getId())
            ->where('is_deleted', 0)
            ->get()
            ->keyBy('kanvas_modules_id');

        $added = 0;
        $kept = 0;
        $deactivated = 0;
        $now = now();

        foreach ($desiredModuleIds as $moduleId) {
            $row = $existing->get($moduleId);
            if ($row === null) {
                AgentKanvasModule::create([
                    'apps_id' => $this->agent->apps_id,
                    'companies_id' => $this->agent->companies_id,
                    'agent_id' => $this->agent->getId(),
                    'kanvas_modules_id' => $moduleId,
                    'config' => null,
                    'is_active' => true,
                    'is_deleted' => false,
                ]);
                $added++;

                continue;
            }

            if (! $row->is_active) {
                $row->update(['is_active' => true]);
            }
            $kept++;
        }

        $stale = $existing->keys()->diff($desiredModuleIds);
        if ($stale->isNotEmpty()) {
            $deactivated = AgentKanvasModule::query()
                ->where('agent_id', $this->agent->getId())
                ->whereIn('kanvas_modules_id', $stale->all())
                ->where('is_active', 1)
                ->update(['is_active' => false, 'updated_at' => $now]);
        }

        return ['added' => $added, 'kept' => $kept, 'deactivated' => $deactivated];
    }

    /**
     * @return array<int>
     */
    private function desiredModuleIds(): array
    {
        $toolIds = $this->collectToolIds();
        if ($toolIds === []) {
            return [];
        }

        return DB::connection('intelligence')
            ->table('nervous_system_tool_kanvas_modules')
            ->whereIn('tool_id', $toolIds)
            ->whereIn('direction', ['consumes', 'both'])
            ->pluck('kanvas_modules_id')
            ->unique()
            ->values()
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * @return array<int>
     */
    private function collectToolIds(): array
    {
        $ids = [];

        if ($this->agent->agent_type_id !== null) {
            $typeToolIds = DB::connection('intelligence')
                ->table('nervous_system_tool_agent_types')
                ->where('agent_type_id', $this->agent->agent_type_id)
                ->pluck('tool_id')
                ->all();
            foreach ($typeToolIds as $id) {
                $ids[(int) $id] = true;
            }
        }

        $grantedIds = DB::connection('intelligence')
            ->table('nervous_system_agent_tools')
            ->where('agent_id', $this->agent->getId())
            ->where('is_active', 1)
            ->where('is_deleted', 0)
            ->pluck('tool_id')
            ->all();
        foreach ($grantedIds as $id) {
            $ids[(int) $id] = true;
        }

        $selectedIds = DB::connection('intelligence')
            ->table('nervous_system_agent_selected_tools')
            ->where('agent_id', $this->agent->getId())
            ->pluck('tool_id')
            ->all();
        foreach ($selectedIds as $id) {
            $ids[(int) $id] = true;
        }

        if ($ids === []) {
            return [];
        }

        return Tool::query()
            ->whereIn('id', array_keys($ids))
            ->where('is_deleted', 0)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }
}
