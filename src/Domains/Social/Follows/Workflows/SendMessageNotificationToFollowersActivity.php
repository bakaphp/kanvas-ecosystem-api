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
        $emailTemplate = $params['email_template'] ?? null;
        $pushTemplate = $params['push_template'] ?? null;
        $notificationMessage = $params['message'] ?? null;
        $notificationTitle = $params['title'] ?? null;
        $subject = $params['subject'] ?? null;
        $viaList = $params['via'] ?? ['mail'];

        // Map notification channels
        $endViaList = array_map(
            [NotificationChannelEnum::class, 'getNotificationChannelBySlug'],
            $viaList
        );

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
            'via' => $endViaList,
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
