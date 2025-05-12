<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Messages;

use Baka\Support\Str;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\UserMessage as MessagesUserMessage;

class UserMessage extends MessagesUserMessage
{
    public function __construct(array|string|int|float|null $content)
    {
        parent::__construct(Message::ROLE_USER, $content);
    }

    /**
     * Override the getContent method to automatically parse any JSON response
     */
    public function getContent(): mixed
    {
        $content = parent::getContent();

        if (is_string($content)) {
            return $this->extractTextFromResponse($content);
        }

        return $content;
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

    /**
     * Get the raw, unprocessed content
     */
    public function getRawContent(): mixed
    {
        return parent::getContent();
    }
}
