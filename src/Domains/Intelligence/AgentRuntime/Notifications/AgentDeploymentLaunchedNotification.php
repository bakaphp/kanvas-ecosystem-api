<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\AgentRuntime\Notifications;

use Kanvas\Intelligence\Agents\Models\AgentDeployment;

class AgentDeploymentLaunchedNotification extends AbstractTemplatedNotification
{
    public const string TEMPLATE_NAME = 'agent_deployment_launched';

    public function __construct(AgentDeployment $deployment)
    {
        parent::__construct($deployment, [
            'company' => $deployment->company,
        ]);

        $this->setSubject(sprintf('Agent "%s" is now running', $deployment->agent?->name ?? 'unknown'));
        $this->setTemplateName(self::TEMPLATE_NAME);
        $this->setData([
            'agent_name' => $deployment->agent?->name ?? 'unknown',
            'machine_name' => $deployment->machine?->name ?? 'unknown',
            'provider' => $deployment->provider ?? 'unknown',
            'deployment_id' => $deployment->getId(),
            'launched_at' => $deployment->launched_at?->toDateTimeString() ?? 'now',
        ]);
    }
}
