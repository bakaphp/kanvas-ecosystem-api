<?php

declare(strict_types=1);

namespace Kanvas\Connectors\OpenClaw\Activities;

use Baka\Contracts\AppInterface;
use Kanvas\Connectors\OpenClaw\Actions\UpdateAgentSwarmHierarchyAction;
use Kanvas\Intelligence\Agents\Models\AgentSwarmMember;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\KanvasActivity;

/**
 * Workflow activity that updates an agent's user_context with its swarm
 * hierarchy info. Delegates to UpdateAgentSwarmHierarchyAction for the actual logic.
 *
 * The agent save in the action triggers the Agent "updated" event, which fires
 * the SyncOpenClawAgentWorkspaceActivity workflow if configured.
 */
class SyncAgentSwarmContextActivity extends KanvasActivity
{
    public $tries = 3;

    public function execute(AgentSwarmMember $member, AppInterface $app, array $params): array
    {
        $this->overwriteAppService($app);

        return $this->executeIntegration(
            entity: $member,
            app: $app,
            integration: IntegrationsEnum::OPENCLAW,
            additionalParams: $params,
            integrationOperation: function ($member): array {
                /** @var AgentSwarmMember $member */
                return new UpdateAgentSwarmHierarchyAction($member->agent)->execute();
            },
            company: $member->swarm->company,
        );
    }
}
