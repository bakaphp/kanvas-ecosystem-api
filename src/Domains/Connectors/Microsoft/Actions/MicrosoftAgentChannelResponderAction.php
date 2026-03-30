<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Microsoft\Actions;

use Kanvas\Connectors\Microsoft\Client as MicrosoftClient;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\Agents\Actions\BaseAgentResponderAction;
use Kanvas\Intelligence\Agents\Helpers\ChatHelper;
use Kanvas\Intelligence\Agents\Types\ADKAgent;
use Kanvas\Social\Messages\Models\Message;
use NeuronAI\Chat\Messages\UserMessage;
use Override;

/**
 * Handles outbound email responses via Microsoft Graph when an AI agent replies.
 *
 * This mirrors the Mailgun AgentChannelResponderAction pattern:
 * 1. Triggered by the AFTER_ADDING_MESSAGE_TO_CHANNEL workflow (via MicrosoftAgentChannelResponderActivity)
 * 2. Reads the inbound email content from the message
 * 3. Passes it to the AI agent for a response
 * 4. Creates a new Kanvas message (child) in the channel
 * 5. Sends the response via Microsoft Graph API
 *
 * Reply threading: if the original message has a Graph message_id, we use
 * Client::replyToMessage() which keeps the reply in the same Outlook conversation thread.
 * Otherwise falls back to Client::sendMail() with "Re: " prefix.
 *
 * The access token is auto-refreshed via Client::getValidAccessToken() before sending.
 *
 * @see \Kanvas\Connectors\Mailgun\Actions\AgentChannelResponderAction
 * @see \Kanvas\Connectors\Microsoft\Workflows\Activities\MicrosoftAgentChannelResponderActivity
 */
class MicrosoftAgentChannelResponderAction extends BaseAgentResponderAction
{
    protected string $messageTypeVerb = 'microsoft-email';
    protected string $communicationChannel = 'email';

    #[Override]
    public function execute(array $params = []): array
    {
        $this->validateInboundMessage();

        $channelId = $this->resolveRecipient();
        $responseText = $this->generateAgentResponse();

        // Creates a child message in the channel (from_me=true, from_ia=true)
        $messageResponse = $this->createMessage(
            $responseText,
            $channelId,
            $this->message,
            $this->channel
        );

        // Only send the actual email if the message isn't locked (support mode can lock it)
        if (! $messageResponse->is_locked) {
            $this->sendMicrosoftEmail($channelId, $responseText, $this->message);
        }

        return [
            'message' => $this->message->message['content'],
            'responseText' => $responseText,
            'response' => $responseText,
        ];
    }

    private function validateInboundMessage(): void
    {
        if (($this->message->message['content'] ?? null) === null) {
            throw new ValidationException('No conversation found');
        }

        if ($this->message->entity() === null) {
            throw new ValidationException('No entity found');
        }
    }

    private function resolveRecipient(): string
    {
        return $this->hijackMessagePhone($this->message->message['from_email']);
    }

    /**
     * Initialize the AI agent and generate a response to the inbound email.
     */
    private function generateAgentResponse(): string
    {
        $currentAgent = new $this->agent->type->handler();
        $currentAgent->setConfiguration($this->agent, $this->message->entity()->people);

        $messageConversation = $this->message->message['content'];

        $question = $currentAgent instanceof ADKAgent
            ? $currentAgent->chat(
                $this->channel,
                $this->message,
                $messageConversation,
                null,
                $this->session
            )
            : $currentAgent->chat(new UserMessage($messageConversation));

        return ChatHelper::extractTextFromResponse($question->getContent());
    }

    /**
     * Send the reply via Microsoft Graph API.
     * Uses replyToMessage() if we have the original Graph message ID (preserves Outlook thread),
     * falls back to sendMail() for new conversations.
     */
    protected function sendMicrosoftEmail(string $toEmail, string $body, Message $message): void
    {
        $accessToken = MicrosoftClient::getValidAccessToken($message->app, $message->company);
        $graphMessageId = $message->message['message_id'] ?? '';
        $subject = $message->message['subject'] ?? 'No subject';

        if ($graphMessageId !== '' && $graphMessageId !== '--') {
            MicrosoftClient::replyToMessage($accessToken, $graphMessageId, $body);
        } else {
            MicrosoftClient::sendMail(
                $accessToken,
                toRecipients: [$toEmail],
                subject: 'Re: ' . $subject,
                body: $body,
            );
        }
    }
}
