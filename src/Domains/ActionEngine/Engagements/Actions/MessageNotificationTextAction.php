<?php

declare(strict_types=1);

namespace Kanvas\ActionEngine\Engagements\Actions;

use Baka\Support\Str;
use Kanvas\ActionEngine\Engagements\Models\Engagement;
use Kanvas\Social\Messages\Models\Message;
use Symfony\Component\ExpressionLanguage\ExpressionLanguage;
use Throwable;

class MessageNotificationTextAction
{
    public function __construct(
        protected Engagement $engagement,
        protected ?Message $overwriteMessage = null
    ) {
        $this->engagement = $engagement;
        $this->overwriteMessage = $overwriteMessage;
    }

    /**
     * Get the msg notification for the given engagement.
     */
    public function notificationText(): string
    {
        return $this->get((string) $this->engagement->stageMessage()->first()?->message_notification);
    }

    /**
     * Get the msg card for the given engagement.
     */
    public function cardText(): string
    {
        return $this->get((string) $this->engagement->stageMessage()->first()?->message);
    }

    /**
     * Generate the msg for the given engagement.
     */
    public function get(string $messageTemplate): string
    {
        $engagementMessage = $this->overwriteMessage === null ? $this->engagement->message : $this->overwriteMessage;
        $message = '';

        // $data = $this->formatMessageData($engagementMessage);
        $data = $engagementMessage->message;
        $messageData = [];
        if (isset($data['message']['data'])) {
            $messageData = Str::isJson((string) $data['message']['data']) ? json_decode($data['message']['data'], true) : $data['message']['data'];
        }
        $entity = $engagementMessage->entity();

        //variables for stage messages
        $values = [
            'sendingUser' => $engagementMessage->user,
            'stage' => $this->engagement->stage,
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

    protected function formatMessageData(string $message): array
    {
        $messageData = Str::isJson($message) ? json_decode($message, true) : ['text' => $message];

        if (isset($messageData['data'])) {
            $messageData['data'] = json_encode($messageData['data']);
        }

        //remove dotted fields
        unset($messageData['preFill']);

        return $messageData;
    }
}
