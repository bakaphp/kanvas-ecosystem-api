<?php

declare(strict_types=1);

namespace Kanvas\Social\Messages\Notifications;

use Baka\Support\Str;
use Kanvas\Notifications\Notification;
use Kanvas\Social\Enums\InteractionEnum;
use Kanvas\Social\Messages\Enums\NotificationTemplateEnum;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Templates\Enums\EmailTemplateEnum;
use Kanvas\Users\Models\Users;

class UserMentionedNotification extends Notification
{
    public function __construct(
        Message $message,
        ?Users $fromUser = null,
    ) {
        $fromUser ??= $message->user;
        $body = $message->contentText();
        $fromName = $fromUser !== null
            ? trim(($fromUser->firstname ?? '') . ' ' . ($fromUser->lastname ?? '')) ?: (string) $fromUser->displayname
            : 'Someone';

        parent::__construct($message, [
            'app' => $message->app,
            'company' => $message->company,
            'fromUser' => $fromUser,
        ]);

        $this->setType(EmailTemplateEnum::BLANK->value);
        $this->setTemplateName(NotificationTemplateEnum::EMAIL_USER_MENTION->value);
        $this->setPushTemplateName(NotificationTemplateEnum::PUSH_NEW_MESSAGE->value);
        $this->setInteraction(InteractionEnum::MENTION->getValue());
        $this->setSubject($fromName . ' mentioned you');

        // `message_id` is the deep-link scalar the client needs to open the thread from a push tap.
        $this->setData([
            'fromUserName' => $fromName,
            'body' => $body,
            'pushMessage' => Str::limit($body, 140),
            'message_id' => (int) $message->getId(),
        ]);

        if ($fromUser !== null) {
            $this->setFromUser($fromUser);
        }

        $this->channels = ['mail', 'push', 'database'];
    }
}
