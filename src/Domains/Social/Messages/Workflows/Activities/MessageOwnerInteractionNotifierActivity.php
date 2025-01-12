<?php

declare(strict_types=1);

namespace Kanvas\Social\Follows\Workflows;

use Baka\Contracts\AppInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Kanvas\Exceptions\ModelNotFoundException as ExceptionsModelNotFoundException;
use Kanvas\Notifications\Enums\NotificationChannelEnum;
use Kanvas\Social\Messages\Notifications\MessageInteractionNotification;
use Kanvas\Workflow\KanvasActivity;

class MessageOwnerInteractionNotifierActivity extends KanvasActivity
{
    public $tries = 3;
    public $queue = 'default';

    public function execute(Model $message, AppInterface $app, array $params = []): array
    {
        $this->overwriteAppService($app);

        $emailTemplate = $params['email_template'] ?? null;
        $pushTemplate = $params['push_template'] ?? null;
        $interaction = $params['interaction'] ?? null;

        if ($interaction === null) {
            return [
                'result' => false,
                'message' => 'Interaction is required',
                'params' => $params,
            ];
        }

        $interactionType = ucfirst($interaction); // Capitalize interaction for consistency
        $notificationMessage = $params['message'] ?? 'New %s from %s on your message';
        $notificationTitle = $params['title'] ?? 'New ' . $interactionType;
        $subject = $params['subject'] ?? $notificationTitle . ' from %s';
        $viaList = $params['via'] ?? ['database'];

        // Map notification channels
        $endViaList = array_map(
            [NotificationChannelEnum::class, 'getNotificationChannelBySlug'],
            $viaList
        );

        $config = [
            'email_template' => $emailTemplate,
            'push_template' => $pushTemplate,
            'app' => $app,
            'company' => $message->company,
            'message' => sprintf($notificationMessage, $interactionType, $message->user->displayname),
            'title' => $notificationTitle,
            'metadata' => $message->getMessage(),
            'subject' => sprintf($subject, $message->user->displayname),
            'via' => $endViaList,
            'fromUser' => $message->user,
            'message_id' => $message->getId(),
            'destination_id' => $message->getId(),
            'destination_type' => $params['destination_type'] ?? 'MESSAGE',
            'destination_event' => $params['destination_event'] ?? 'NEW_MESSAGE',
        ];

        try {
            $newMessageNotification = new MessageInteractionNotification(
                $message,
                $config,
                $config['via']
            );

            $message->user->notify($newMessageNotification);
        } catch (ModelNotFoundException|ExceptionsModelNotFoundException $e) {
            return [
                'result' => false,
                'message' => 'Error in notification to user',
                'exception' => $e,
            ];
        }

        return [
            'result' => true,
            'message' => 'Interaction Notification sent to message owner',
            'data' => $config,
            'message_id' => $message->getId(),
        ];
    }
}
