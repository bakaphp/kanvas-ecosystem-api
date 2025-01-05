<?php

declare(strict_types=1);

namespace Kanvas\Social\Follows\Workflows;

use Baka\Contracts\AppInterface;
use Baka\Traits\KanvasJobsTrait;
use Illuminate\Database\Eloquent\Model;
use Kanvas\Notifications\Enums\NotificationChannelEnum;
use Kanvas\Social\Messages\Jobs\SendMessageNotificationsToAllFollowersJob;
use Kanvas\Workflow\Contracts\WorkflowActivityInterface;
use Workflow\Activity;

class SendMessageNotificationToFollowersActivity extends Activity implements WorkflowActivityInterface
{
    use KanvasJobsTrait;
    public $tries = 3;

    public function execute(Model $message, AppInterface $app, array $params = []): array
    {
        /**
         * @todo
         *
         * send notification activity shouldnt be just push
         * follow the same logic as follow
         *  base on configuration
         *   - email tempate, push template
         *   - message
         *   - adicitonal params
         *
         *   - send to thequeueu for distribution
         */
        $emailTemplate = $params['email_template'] ?? null;
        $pushTemplate = $params['push_template'] ?? null;
        $notificationMessage = $params['message'] ?? null;
        $notificationTitle = $params['title'] ?? null;
        $subject = $params['subject'] ?? null;
        $via = ['mail'];

        foreach ($params['via'] as $via) {
            $via[] = NotificationChannelEnum::getNotificationChannelBySlug($via);
        }

        $notificationMetaData = array_merge([
            'destination_id' => $message->getId(),
            'destination_type' => $params['destination_type'] ?? 'MESSAGE',
            'destination_event' => $params['destination_event'] ?? 'NEW_MESSAGE',
        ], $params['metadata'] ?? []);

        $config = [
            'email_template' => $emailTemplate,
            'push_template' => $pushTemplate,
            'app' => $app,
            'company' => $message->company,
            'message' => $notificationMessage,
            'title' => $notificationTitle,
            'metadata' => $notificationMetaData,
            'subject' => $subject,
            'via' => $via,
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
