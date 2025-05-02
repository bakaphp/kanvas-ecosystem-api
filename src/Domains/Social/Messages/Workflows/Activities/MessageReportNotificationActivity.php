<?php

declare(strict_types=1);

namespace Kanvas\Social\Messages\Workflows\Activities;

use Baka\Contracts\AppInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Notification;
use Kanvas\Notifications\Templates\Blank;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\Messages\Services\MessageInteractionService;
use Kanvas\Users\Repositories\UsersRepository;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\KanvasActivity;

class MessageReportNotificationActivity extends KanvasActivity
{
    public $tries = 1;

    public function execute(Model $message, AppInterface $app, array $params = []): array
    {
        $this->overwriteAppService($app);

        /**
         * @todo send notification to rotation of users?
         */

        $reportMessageId = $message->message['message_id'] ?? null;

        if ($reportMessageId === null) {
            return [
                'result' => false,
                'message_id' => $message->getId(),
                'message' => 'No message id found in the report',
            ];
        }

        return $this->executeIntegration(
            entity: $message,
            app: $app,
            integration: IntegrationsEnum::INTERNAL,
            integrationOperation: function ($message, $app, $integrationCompany, $additionalParams) use ($reportMessageId) {
                $message = Message::getById($reportMessageId, $app);

                $messageInteractionService = new MessageInteractionService($message);
                $messageInteractionService->report($message->user);

                //@todo add total reports to the message
                $message->total_disliked += 1;
                $message->update();

                $messageData = $message->message;
                $usersToNotify = UsersRepository::findUsersByArray($app->get('owner_notification'), $app);
                $notification = new Blank(
                    'flagged-message',
                    [
                        'message' => 'New Report',
                        'messageData' => $messageData,
                    ],
                    ['mail'],
                    $message,
                );

                $notification->setSubject('New report from ' . $message->user->displayname);
                Notification::send($usersToNotify, $notification);

                return [
                    'result' => true,
                    'message' => 'New report from ' . $message->user->getName(),
                    'message_id' => $message->getId(),
                ];
            },
            company: $message->company,
        );
    }
}
