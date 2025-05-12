<?php

declare(strict_types=1);

namespace Kanvas\Connectors\WaSender\Actions;

use Baka\Support\Str;
use Kanvas\Connectors\WaSender\Services\MessageService;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\Agents\Messages\UserMessage;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Types\CRMAgent;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Messages\Models\Message;

class AgentChannelResponderAction
{
    public function __construct(
        protected Channel $channel,
        protected Message $message,
        protected Agent $agent
    ) {
    }

    public function execute(array $params = []): array
    {
        $messageConversation = $this->message->message['raw_data']['message']['conversation'] ?? null;
        $channelId = Str::replace('@s.whatsapp.net', '', $this->message->message['chat_jid']);

        if ($this->message->entity() === null) {
            throw new ValidationException('No entity found');
        }

        $crmAgent = CRMAgent::make();
        $crmAgent->setConfiguration(
            $this->agent,
            $this->message->entity()
        );

        $question = $crmAgent->chat(new UserMessage($messageConversation));
        $responseContent = $question->getContent();

        // Extract text from response that might be formatted with markdown code blocks
        //$responseText = $this->extractTextFromResponse($responseContent);

        $whatsAppMessageService = new MessageService(
            $this->message->app,
            $this->message->company
        );

        return $whatsAppMessageService->sendTextMessage(
            $channelId,
            $responseContent
        );
    }

    /**
     * Extract plain text from a response that might contain JSON in code blocks
     */
    protected function extractTextFromResponse(string $response): string
    {
        // Check if the response has markdown code blocks with JSON
        if (preg_match('/```(?:json)?\s*(\{.*\})\s*```/s', $response, $matches)) {
            $jsonString = $matches[1];
            $data = json_decode($jsonString, true);

            if (json_last_error() === JSON_ERROR_NONE && isset($data['response'])) {
                return $data['response'];
            }
        }

        // Alternative: strip markdown code block formatting and try to parse
        $cleanedResponse = preg_replace('/```(?:json)?\s*(.*)\s*```/s', '$1', $response);

        if (Str::isJson($cleanedResponse)) {
            $data = json_decode($cleanedResponse, true);
            if (isset($data['response'])) {
                return $data['response'];
            }
        }

        // If all else fails, just strip markdown formatting and return
        return preg_replace('/```(?:json)?\s*(.*)\s*```/s', '$1', $response);
    }
}
