<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Capability\Observers;

use Kanvas\Intelligence\Agents\Actions\ReconcileAgentKanvasModulesAction;
use Kanvas\NervousSystem\Capability\Models\AgentTool;
use Throwable;

class AgentToolObserver
{
    public function saved(AgentTool $agentTool): void
    {
        $this->reconcile($agentTool);
    }

    public function deleted(AgentTool $agentTool): void
    {
        $this->reconcile($agentTool);
    }

    private function reconcile(AgentTool $agentTool): void
    {
        // Must not block the grant/revoke — a missed reconcile is recoverable
        // via the sync command.
        try {
            $agent = $agentTool->agent;
            if ($agent === null) {
                return;
            }
            new ReconcileAgentKanvasModulesAction($agent)->execute();
        } catch (Throwable $e) {
            report($e);
        }
    }
}
