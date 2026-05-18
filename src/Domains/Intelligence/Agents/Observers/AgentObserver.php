<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Observers;

use Kanvas\Intelligence\Agents\Actions\ReconcileAgentKanvasModulesAction;
use Kanvas\Intelligence\Agents\Models\Agent;
use Throwable;

class AgentObserver
{
    public function saved(Agent $agent): void
    {
        $agent->clearLightHouseCache(withKanvasConfiguration: false);
    }

    public function created(Agent $agent): void
    {
        $this->reconcileKanvasModules($agent);
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
