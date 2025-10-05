<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Mailgun\Actions;

use Illuminate\Support\Facades\Notification;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\Agents\Helpers\ChatHelper;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Types\ADKAgent;
use Kanvas\Intelligence\Sessions\Models\Session;
use Kanvas\Notifications\Templates\Blank;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Messages\Models\Message;
use NeuronAI\Chat\Messages\UserMessage;

class AgentChannelResponderAction
{
    public function __construct(
        protected Channel $channel,
        protected Message $message,
        protected Agent $agent,
        protected ?Session $session = null,
    ) {
    }

    public function execute(array $params = []): array
    {
        //$messageConversation = $this->message->message['raw_data']['message']['conversation'] ?? null;
        $messageConversation = $this->message->message['content'];

        $channelId = $this->hijackMessagePhone($this->message->message['from_email']);

        if ($messageConversation === null) {
            throw new ValidationException('No conversation found');
        }

        //entity is a lead
        if ($this->message->entity() === null) {
            throw new ValidationException('No entity found');
        }

        $currentAgent = new $this->agent->type->handler();
        //$currentAgent = $this->agent;

        $currentAgent->setConfiguration(
            $this->agent,
            $this->message->entity()->people
        );

        $emailRequest = [
            'template_name' => 'agent-email-response',
            'email' => $channelId, //$this->message->message['from_email'],
            'subject' => 'Re: ' . ($this->message->message['subject'] ?? 'No subject'),
        ];

        // Define the callback to send each chunk in real time
        /*    $onChunk = function ($text, $data) use ($emailRequest): void {
               //$whatsAppMessageService->sendTextMessage($channelId, $text);
               $this->sendEmail($emailRequest, ['content' => $text], $this->message->user);
           }; */
        $onChunk = null;
        $question = $currentAgent instanceof ADKAgent ?
        $currentAgent->chat(
            $this->channel,
            $this->message,
            $messageConversation,
            $onChunk,
            $this->session
        ) : $currentAgent->chat(new UserMessage($messageConversation));

        $responseContent = $question->getContent();

        // Extract text from response that might be formatted with markdown code blocks
        $responseText = ChatHelper::extractTextFromResponse($responseContent);

        $emailData = [
            'content' => $responseText,
            'lead' => $this->message->entity(),
            'company' => $this->message->company,
        ];

        $this->sendEmail($emailRequest, $emailData, $this->message);

        return [
            'message' => $messageConversation,
            'responseText' => $responseContent,
            'response' => $responseText,
        ];
    }

    protected function hijackMessagePhone(string $channelId): string
    {
        if ($this->agent->company->get('allow_session_hijack', false)
          && $this->agent->company->get('overwrite_phone_number') !== null
        ) {
            $overwriteConfig = $this->agent->company->get('overwrite_phone_number');
            $originalRemoteJid = $channelId;

            // Reverse lookup: hijacked -> original
            $reverseMapping = array_flip($overwriteConfig);
            if (isset($reverseMapping[$originalRemoteJid])) {
                return $reverseMapping[$originalRemoteJid];
            }
        }

        return $channelId;
    }

    protected function sendEmail(array $request, array $data, Message $message): void
    {
        $notification = new Blank(
            $request['template_name'],
            $data,
            ['mail'],
            $message
        );
        $notification->setFromUser($message->user);
        $notification->setSubject($request['subject']);
        Notification::route('mail', $request['email'])->notify($notification);
    }
}
