<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Notifications;

use Baka\Support\Str;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Notifications\Enums\NotificationTemplateEnum;
use Kanvas\Notifications\Notification;
use Kanvas\Social\Messages\Models\Message;

class AgentRepliedToMentionNotification extends Notification
{
    public function __construct(
        protected Message $reply,
        protected Agent $agent,
    ) {
        $fromUser = $agent->user;
        $body = $reply->contentText();

        parent::__construct($reply, [
            'app' => $reply->app,
            'company' => $agent->company,
            'fromUser' => $fromUser,
        ]);

        $this->setType('blank');
        $this->setTemplateName(NotificationTemplateEnum::EMAIL_AGENT_MENTION_REPLY->value);
        $this->setPushTemplateName(NotificationTemplateEnum::PUSH_AGENT_MENTION_REPLY->value);
        $this->setDatabaseTemplateName(NotificationTemplateEnum::DATABASE_AGENT_MENTION_REPLY->value);
        $this->setSubject($agent->name . ' replied to your message');

        // Template variables. `message_id` is the one deep-link scalar the client needs to
        // open the thread from a push tap (the DB channel also stores it as entity_id).
        $this->setData([
            'agentName' => $agent->name,
            'body' => $body,
            'pushMessage' => Str::limit($body, 140),
            'message_id' => (int) $reply->getId(),
        ]);

        if ($fromUser !== null) {
            $this->setFromUser($fromUser);
        }

        $this->channels = ['mail', 'push', 'database'];
    }
}
