<?php

declare(strict_types=1);

namespace Kanvas\Social\Messages\Workflows\Activities;

use Baka\Contracts\AppInterface;
use Illuminate\Database\Eloquent\Model;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\Messages\Services\MessageInteractionService;
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

        $message = Message::getById($reportMessageId, $app);

        $messageInteractionService = new MessageInteractionService($message);
        $messageInteractionService->report($message->user);

        //@todo add total reports to the message
        $message->total_disliked += 1;
        $message->update();

        return [
            'result' => true,
            'message' => 'New report from ' . $message->user->getName(),
            'message_id' => $message->getId(),
        ];
    }
}
