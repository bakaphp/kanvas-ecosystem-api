<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\AgentRuntime\Notifications;

use Kanvas\Intelligence\Agents\Models\AgentDeployment;
use Throwable;

class AgentMigrationNotification extends AbstractTemplatedNotification
{
    public const string TEMPLATE_NAME = 'agent_migration_result';

    public function __construct(
        AgentDeployment $sourceDeployment,
        ?AgentDeployment $destinationDeployment,
        bool $success,
        ?Throwable $error = null,
    ) {
        parent::__construct($sourceDeployment, [
            'company' => $sourceDeployment->company,
        ]);

        $agentName = $sourceDeployment->agent?->name ?? 'unknown';
        $this->setSubject(
            $success
                ? sprintf('Migration completed for agent "%s"', $agentName)
                : sprintf('Migration FAILED for agent "%s"', $agentName),
        );
        $this->setTemplateName(self::TEMPLATE_NAME);
        $this->setData([
            'success' => $success,
            'agent_name' => $agentName,
            'source_deployment_id' => $sourceDeployment->getId(),
            'destination_deployment_id' => $destinationDeployment?->getId() ?? 0,
            'destination_machine_name' => $destinationDeployment?->machine?->name ?? 'unknown',
            'error_message' => $error?->getMessage() ?? 'no details captured',
        ]);
    }
}
