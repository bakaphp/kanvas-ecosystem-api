<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Notifications;

use Baka\Support\Str;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Notifications\Notification;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Users\Models\Users;
use Override;

class AgentReplyNotification extends Notification
{
    public function __construct(
        protected Message $reply,
        protected Agent $agent,
        ?Users $fromUser = null,
    ) {
        parent::__construct($reply, [
            'app' => $reply->app,
            'company' => $this->agent->company,
            'fromUser' => $fromUser,
        ]);

        // Scalar-only fields so they survive the Expo data filter (NotificationExpoTrait
        // drops every non-scalar) and are available for deep-linking in the mobile app.
        $this->setData([
            'type' => 'agent_reply',
            'message_id' => (int) $this->reply->getId(),
            'agent_id' => (int) $this->agent->getId(),
            'session_id' => (string) ($this->reply->getMessage()['session_id'] ?? ''),
            'agent_name' => $this->agent->name,
            'company' => $this->agent->company->name,
            'company_id' => $this->agent->companies_id,
            'company_uuid' => $this->agent->company->uuid,
        ]);

        $this->channels = ['push', 'expo'];
    }

    #[Override]
    public function getNotificationTitle(): string
    {
        return $this->agent->name;
    }

    #[Override]
    protected function getPushTemplate(): string
    {
        return json_encode([
            'title' => $this->agent->name,
            'message' => Str::limit($this->replyContent(), 140),
            'subtitle' => null,
            'data' => [
                'message_id' => (int) $this->reply->getId(),
                'agent_id' => (int) $this->agent->getId(),
                'agent_name' => $this->agent->name,
                'company' => $this->agent->company->name,
                'company_id' => $this->agent->companies_id,
                'company_uuid' => $this->agent->company->uuid,
            ],
        ], JSON_THROW_ON_ERROR);
    }

    private function replyContent(): string
    {
        return (string) ($this->reply->getMessage()['content'] ?? '');
    }
}
