<?php

declare(strict_types=1);

namespace Kanvas\Connectors\SalesAssist\Services;

use Kanvas\ActionEngine\Engagements\Models\Engagement;
use Kanvas\Social\Messages\Models\Message;
use Symfony\Component\ExpressionLanguage\ExpressionLanguage;
use Throwable;

class MessageNotificationTextService
{
    public function __construct(
        protected Engagement $engagement,
        protected ?Message $overwriteMessage = null
    ) {
    }

    /**
     * Get the msg notification for the given engagement.
     */
    public function notificationText(): string
    {
        return $this->get((string) $this->engagement->stageMessage->message_notification);
    }

    /**
     * Get the msg card for the given engagement.
     */
    public function cardText(): string
    {
        return $this->get((string) $this->engagement->stageMessage->message);
    }

    /**
     * Generate the msg for the given engagement.
     */
    public function get(string $messageTemplate): string
    {
        $engagementMessage = $this->overwriteMessage === null ? $this->engagement->message : $this->overwriteMessage;
        $message = '';
        //$documents = new ElasticMessageDocuments();
        $data = $engagementMessage->toArray();
        $messageData = [];
        if (isset($data['message']['data'])) {
            $messageData = $data['message']['data'];
        }
        $entity = $engagementMessage->entity();

        //variables for stage messages
        $values = [
            'sendingUser' => $engagementMessage->user,
            'stage' => $this->engagement->getStage(),
            'message' => $engagementMessage,
            'messageData' => $messageData,
            'companyAction' => $this->engagement->companyAction,
            'contact' => is_object($entity->people) ? trim($entity->people->name) : $data['custom_fields']['contact']['contact'],
            'postTitle' => $this->engagement->companyAction ? $this->engagement->companyAction->name : $data['message']['text'],
            'user' => ! empty($engagementMessage->user->firstname) ? $engagementMessage->user->firstname . ' ' . $engagementMessage->user->lastname : $engagementMessage->user->displayname,
            'year' => $messageData['form']['year'] ?? '',
            'make' => $messageData['form']['make'] ?? '',
            'model' => $messageData['form']['model'] ?? '',
            'trim' => $messageData['form']['trim'] ?? '',
            'bank' => $messageData['bank']['name'] ?? '',
            'shareDate' => $this->engagement->getShareDate(),
        ];

        try {
            $expressionLanguage = new ExpressionLanguage();

            $message = $expressionLanguage->evaluate(
                $messageTemplate,
                $values
            );
        } catch (Throwable $e) {
        }

        return $message;
    }
}
