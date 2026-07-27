<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\AgentRuntime\Notifications;

use Kanvas\Intelligence\Agents\Models\AgentDeployment;

class AgentDeploymentTerminatedNotification extends AbstractTemplatedNotification
{
    public const string TEMPLATE_NAME = 'agent_deployment_terminated';

    public function __construct(AgentDeployment $deployment)
    {
        parent::__construct($deployment, [
            'company' => $deployment->company,
        ]);

        $this->setSubject(sprintf('Agent "%s" has been terminated', $deployment->agent?->name ?? 'unknown'));
        $this->setTemplateName(self::TEMPLATE_NAME);
        $this->setData([
            'agent_name' => $deployment->agent?->name ?? 'unknown',
            'machine_name' => $deployment->machine?->name ?? 'unknown',
            'deployment_id' => $deployment->getId(),
            'terminated_at' => $deployment->terminated_at?->toDateTimeString() ?? 'now',
        ]);
    }
}
