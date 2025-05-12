<?php

declare(strict_types=1);

namespace Kanvas\Connectors\WaSender\Actions;

use Baka\Support\Str;
use Kanvas\Connectors\WaSender\Services\MessageService;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Types\CRMAgent;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Messages\Models\Message;
use NeuronAI\Chat\Messages\UserMessage;

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

        $crmAgent = new CRMAgent();
        $crmAgent->setConfiguration(
            $this->agent,
            $this->message->entity()
        );

        $question = $crmAgent->chat(new UserMessage($messageConversation));
        $responseContent = $question->getContent();

        // Extract text from response that might be formatted with markdown code blocks
        $responseText = $this->extractTextFromResponse($responseContent);

        $whatsAppMessageService = new MessageService(
            $this->message->app,
            $this->message->company
        );

        return $whatsAppMessageService->sendTextMessage(
            $channelId,
            $responseText
        );
    }

    /**
     * Extract plain text from a response that might contain JSON with a response field
     */
    protected function extractTextFromResponse(string $response): string
    {
        // First try: direct JSON parsing if the entire response is JSON
        if (Str::isJson($response)) {
            $data = json_decode($response, true);
            if (json_last_error() === JSON_ERROR_NONE && isset($data['response'])) {
                return $data['response'];
            }
        }

        // Second try: Check if response has markdown code blocks with JSON
        if (preg_match('/```(?:json)?\s*(\{.*\})\s*```/s', $response, $matches)) {
            $jsonString = $matches[1];
            $data = json_decode($jsonString, true);

            if (json_last_error() === JSON_ERROR_NONE && isset($data['response'])) {
                return $data['response'];
            }
        }

        // Third try: Handle JSON without code blocks or with malformed blocks
        // Find anything that looks like a JSON object
        if (preg_match('/\{.*"response"\s*:\s*"(.*?)"\s*(?:,.*?)?\}/s', $response, $matches)) {
            // This handles cases where we have: {"response": "text"} anywhere in the string
            return str_replace('\n', "\n", $matches[1]); // Handle escaped newlines
        }

        // Fourth try: Look for a JSON string anywhere in the response
        if (preg_match('/\{.*\}/s', $response, $matches)) {
            $possibleJson = $matches[0];
            $data = json_decode($possibleJson, true);

            if (json_last_error() === JSON_ERROR_NONE && isset($data['response'])) {
                return $data['response'];
            }
        }

        // Fifth try: Strip markdown code block formatting and try to parse
        $cleanedResponse = preg_replace('/```(?:json)?\s*(.*)\s*```/s', '$1', $response);

        // Last resort: return the original response with markdown formatting removed
        return preg_replace('/```(?:json)?\s*(.*)\s*```/s', '$1', $response);
    }
}
