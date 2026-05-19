<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Observers;

use Kanvas\Intelligence\Agents\Actions\ReconcileAgentKanvasModulesAction;
use Kanvas\Intelligence\Agents\Models\Agent;
use Throwable;

class AgentObserver
{
    public function saving(Agent $agent): void
    {
        $this->syncLegacyRole($agent);
    }

    public function saved(Agent $agent): void
    {
        $agent->clearLightHouseCache(withKanvasConfiguration: false);
    }

    public function created(Agent $agent): void
    {
        $this->reconcileKanvasModules($agent);
    }

    private function syncLegacyRole(Agent $agent): void
    {
        $map = [
            'soul' => 'background',
            'instructions' => 'steps',
            'output_format' => 'output',
        ];

        $dirty = array_filter(
            array_keys($map),
            fn (string $field): bool => $agent->isDirty($field),
        );

        if ($dirty === []) {
            return;
        }

        $role = is_array($agent->role) ? $agent->role : [];

        foreach ($dirty as $field) {
            $role[$map[$field]] = $agent->{$field};
        }

        $agent->role = $role;
    }

    private function reconcileKanvasModules(Agent $agent): void
    {
        // Must not block agent save — a missed subscription is recoverable.
        try {
            new ReconcileAgentKanvasModulesAction($agent)->execute();
        } catch (Throwable $e) {
            report($e);
        }
    }
}
