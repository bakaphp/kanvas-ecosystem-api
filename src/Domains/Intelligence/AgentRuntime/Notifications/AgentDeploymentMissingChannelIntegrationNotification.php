<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\AgentRuntime\Notifications;

use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Notifications\Notification;
use Kanvas\Templates\Actions\RenderTemplateAction;
use Override;

class AgentDeploymentMissingChannelIntegrationNotification extends Notification
{
    public const string TEMPLATE_NAME = 'agent_deployment_missing_channel_integration';

    public array $channels = ['mail'];

    /**
     * @param array<int, string> $missingRequirements
     */
    public function __construct(
        Agent $agent,
        string $provider,
        array $missingRequirements,
    ) {
        parent::__construct($agent, [
            'company' => $agent->company,
        ]);

        $this->setSubject(sprintf('Agent "%s" needs a messaging integration before deployment', $agent->name));
        $this->setTemplateName(self::TEMPLATE_NAME);
        $this->setData([
            'agent_name' => $agent->name,
            'provider' => $provider,
            'missing_requirements' => $missingRequirements,
        ]);
    }

    #[Override]
    public function getEmailContent(): string
    {
        return new RenderTemplateAction($this->app, $this->company)
            ->execute(self::TEMPLATE_NAME, $this->getData());
    }
}
