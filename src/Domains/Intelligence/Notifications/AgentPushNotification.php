<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Notifications;

use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Notifications\Notification;
use Override;

/**
 * Template-free push (OneSignal + Expo) an agent sends to a user's registered devices —
 * title/message come straight from the agent's tool call, not from a stored push template.
 */
class AgentPushNotification extends Notification
{
    public function __construct(
        protected Agent $agent,
        protected string $title,
        protected string $body,
    ) {
        parent::__construct($agent, [
            'app' => $agent->app,
            'company' => $agent->company,
            'fromUser' => $agent->user,
        ]);

        // Scalar-only fields so they survive the Expo data filter (NotificationExpoTrait
        // drops every non-scalar) and are available for deep-linking in the mobile app.
        $this->setData([
            'type' => 'agent_push',
            'title' => $title,
            'message' => $body,
            'agent_id' => (int) $agent->getId(),
            'agent_name' => $agent->name,
            'company_id' => $agent->companies_id,
            'company_uuid' => $agent->company->uuid,
        ]);

        $this->channels = ['push', 'expo'];
    }

    #[Override]
    public function getNotificationTitle(): string
    {
        return $this->title;
    }

    #[Override]
    protected function getPushTemplate(): string
    {
        return json_encode([
            'title' => $this->title,
            'message' => $this->body,
            'subtitle' => null,
        ], JSON_THROW_ON_ERROR);
    }
}
