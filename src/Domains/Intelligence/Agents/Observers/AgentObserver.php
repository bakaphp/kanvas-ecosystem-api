<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Observers;

use Kanvas\Intelligence\AgentRuntime\Providers\AgentRuntimeProviderFactory;
use Kanvas\Intelligence\Agents\Actions\ReconcileAgentKanvasModulesAction;
use Kanvas\Intelligence\Agents\Models\Agent;
use Throwable;

class AgentObserver
{
    public function deleting(Agent $agent): void
    {
        // Dispatch container termination for every active deployment before CascadeSoftDeletes
        // flips is_deleted — otherwise the containers keep running on the machine indefinitely.
        foreach ($agent->deployments()->where('is_deleted', 0)->get() as $deployment) {
            if ($deployment->status === 'terminated') {
                continue;
            }

            try {
                AgentRuntimeProviderFactory::forDeployment($deployment)->dispatchTermination($deployment);
            } catch (Throwable $e) {
                report($e);
            }
        }
    }

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
