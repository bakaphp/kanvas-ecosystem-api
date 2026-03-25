<?php

declare(strict_types=1);

namespace Kanvas\Connectors\OpenClaw\Activities;

use Baka\Contracts\AppInterface;
use Kanvas\Connectors\OpenClaw\Actions\SyncAgentWorkspaceAction;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentDeployment;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\KanvasActivity;

/**
 * Workflow activity that syncs an agent's workspace files to its running
 * deployment. Delegates to SyncAgentWorkspaceAction for the actual logic.
 */
class SyncOpenClawAgentWorkspaceActivity extends KanvasActivity
{
    public $tries = 3;

    public function execute(Agent $agent, AppInterface $app, array $params): array
    {
        $this->overwriteAppService($app);

        $deployment = $agent->activeDeployment;

        if (! $deployment instanceof AgentDeployment || ! $deployment->isRunning()) {
            return $this->failWorkflow([
                'success' => false,
                'message' => 'Agent has no active running deployment, skipping workspace sync',
            ]);
        }

        return $this->executeIntegration(
            entity: $agent,
            app: $app,
            integration: IntegrationsEnum::OPENCLAW,
            additionalParams: $params,
            integrationOperation: function () use ($agent, $deployment): array {
                return new SyncAgentWorkspaceAction($agent, $deployment)->execute();
            },
            company: $agent->company,
        );
    }
}
