<?php

declare(strict_types=1);

namespace Kanvas\Social\Messages\Workflows\Activities;

use Baka\Contracts\AppInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Kanvas\Exceptions\ModelNotFoundException as ExceptionsModelNotFoundException;
use Kanvas\Notifications\Enums\NotificationChannelEnum;
use Kanvas\Social\Messages\Notifications\NewMessageNotification;
use Kanvas\Workflow\KanvasActivity;

class MessageOwnerChildNotificationActivity extends KanvasActivity
{
    public $tries = 1;

    public function execute(Model $message, AppInterface $app, array $params = []): array
    {
        $this->overwriteAppService($app);

        $emailTemplate = $params['email_template'] ?? null;
        $pushTemplate = $params['push_template'] ?? null;

        if (empty($message->parent_id)) {
            return [
                'result' => false,
                'message_id' => $message->getId(),
                'message' => 'Only child messages can send notification to its parent owner',
            ];
        }

        $notificationMessage = $params['message'] ?? 'New message from %s';
        $notificationTitle = $params['title'] ?? 'New message';
        $subject = $params['subject'] ?? 'New message from %s';
        $viaList = $params['via'] ?? ['database'];

        // Map notification channels
        $endViaList = array_map(
            [NotificationChannelEnum::class, 'getNotificationChannelBySlug'],
            $viaList
        );

        $metaData = $message->getMessage();
        unset($metaData['ai_nugged']); //@todo move this to a customization
        unset($metaData['nugget']); //@todo move this to a customization

        $config = [
            'email_template' => $emailTemplate,
            'push_template' => $pushTemplate,
            'app' => $app,
            'company' => $message->company,
            'message' => sprintf($notificationMessage, $message->user->displayname),
            'title' => $notificationTitle,
            'metadata' => $metaData,
            'subject' => sprintf($subject, $message->user->displayname),
            'via' => $endViaList,
            'fromUser' => $message->user,
            'message_owner_id' => $message->user->getId(),
            'from_user_id' => $message->user->getId(),
            'message_id' => $message->getId(),
            'parent_message_id' => $message->parent ? $message->parent->getId() : $message->getId(),
            'destination_id' => $message->getId(),
            'destination_type' => $params['destination_type'] ?? 'MESSAGE',
            'destination_event' => $params['destination_event'] ?? 'NEW_MESSAGE',
        ];

        if ($message->parent->users_id == $message->users_id) {
            return [
                'result' => false,
                'message_id' => $message->getId(),
                'message' => 'Message owner is the same as the parent owner',
            ];
        }

        try {
            $newMessageNotification = new NewMessageNotification(
                $message,
                $config,
                $config['via']
            );
            $newMessageNotification->setFromUser($message->user);

            $message->parent->user->notify($newMessageNotification);
        } catch (ModelNotFoundException|ExceptionsModelNotFoundException $e) {
            return [
                'result' => false,
                'message_id' => $message->getId(),
                'message' => 'Error in notification to user',
                'exception' => $e,
            ];
        }

        return [
            'result' => true,
            'message' => 'New message notification sent to message owner',
            'data' => $config,
            'message_id' => $message->getId(),
        ];
    }
}
