<?php

declare(strict_types=1);

namespace Kanvas\Social\Follows\Workflows;

use Baka\Contracts\AppInterface;
use Illuminate\Database\Eloquent\Model;
use Kanvas\Notifications\Enums\NotificationChannelEnum;
use Kanvas\Social\Messages\Jobs\SendMessageNotificationsToAllFollowersJob;
use Kanvas\Workflow\KanvasActivity;

class SendMessageNotificationToFollowersActivity extends KanvasActivity
{
    /**
     * todo we cap this to 3 tries for now. because of the
     * issue encounter with onesignal Data Data must be no more than 2048 bytes long , to avoid infinite loop
     */
    public $tries = 1;

    public function execute(Model $message, AppInterface $app, array $params = []): array
    {
        $this->overwriteAppService($app);

        $emailTemplate = $params['email_template'] ?? null;
        $pushTemplate = $params['push_template'] ?? null;
        $notificationMessage = $params['message'] ?? 'New message from %s';
        $notificationTitle = $params['title'] ?? 'New Message';
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
            'message_id' => $message->getId(),
            'parent_message_id' => $message->parent ? $message->parent->getId() : $message->getId(),
            'destination_id' => $message->getId(),
            'destination_type' => $params['destination_type'] ?? 'MESSAGE',
            'destination_event' => $params['destination_event'] ?? 'NEW_MESSAGE',
        ];

        SendMessageNotificationsToAllFollowersJob::dispatch(
            $message,
            $config,
        );

        return [
            'result' => true,
            'message' => 'Notification Message sent to all followers',
            'data' => $config,
            'message_id' => $message->getId(),
        ];
    }
}
