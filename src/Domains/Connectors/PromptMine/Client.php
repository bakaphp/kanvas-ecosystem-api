<?php

declare(strict_types=1);

namespace Kanvas\Connectors\PromptMine;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\ClientException;
use Kanvas\Connectors\PromptMine\Enums\ConfigurationEnum;

class Client
{
    protected string $baseUrl;
    protected string $apiEnv;
    protected GuzzleClient $client;

    public function __construct(
        protected AppInterface $app,
        protected ?CompanyInterface $company = null
    ) {
        $this->baseUrl = $this->app->get(ConfigurationEnum::BASE_URL->value);
        $this->apiEnv = $this->app->get(ConfigurationEnum::API_ENV->value) ?? 'api';

        if (empty($this->baseUrl)) {
            throw new \InvalidArgumentException('Base URL for PromptMine is not configured.');
        }

        $this->client = new GuzzleClient([
            'base_uri' => $this->baseUrl,
            'headers' => [
                'Content-Type' => 'application/json',
                'User-Agent' => 'Kanvas-Prompt-Client/1.0',
            ],
        ]);
    }

    /**
     * Generate a chat response using text-to-text model.
     *
     * @param array $messages Array of chat messages in the format [['role' => 'user', 'content' => 'message']]
     * @param array $aiModel The AI model configuration from message
     * @return array The API response containing chat data
     */
    public function generateChatResponse(array $messages, array $aiModel): array
    {
        // Extract provider and model from ai_model configuration
        $provider = $aiModel['key'] ?? 'gemini';
        $modelId = $aiModel['value'] ?? 'gemini-2.0-flash';

        $endpoint = "/{$this->apiEnv}/v2/chat/{$provider}/qa";

        $data = [
            'messages' => $messages,
        ];

        $queryParams = [
            'modelId' => $modelId,
        ];

        $response = $this->post($endpoint, $data, $queryParams);

        return $response;
    }

    /**
     * Extract the response text from the chat API response.
     *
     * @param array $response The API response
     * @return string|null The response text or null if not found
     */
    public function extractChatResponseText(array $response): ?string
    {
        // The response should contain the assistant's message
        if (isset($response['responseText'])) {
            return $response['responseText'];
        }

        // Fallback: look for common response patterns
        if (isset($response['response'])) {
            return $response['response'];
        }

        if (isset($response['text'])) {
            return $response['text'];
        }

        return null;
    }

    /**
     * Extract the chat history from the response while excluding the latest response.
     *
     * @param array $messages The original messages sent
     * @return array Array of chat history messages
     */
    public function extractChatHistory(array $messages): array
    {
        $history = [];

        // Add all original messages to history
        foreach ($messages as $message) {
            $history[] = [
                'role' => $message['role'],
                'content' => $message['content'],
                'timestamp' => time(),
            ];
        }

        return $history;
    }

    /**
     * Get the full conversation including the new response.
     *
     * @param array $messages The original messages sent
     * @param array $response The API response
     * @return array Complete conversation array
     */
    public function getFullConversation(array $messages, ?array $response = null): array
    {
        $conversation = $this->extractChatHistory($messages);

        // $responseText = $this->extractChatResponseText($response);
        if ($response) {
            $conversation[] = [
                'role' => 'assistant',
                'content' => $response,
                'timestamp' => time(),
            ];
        }

        return $conversation;
    }

    /**
     * Generate an image using text-to-image model.
     *
     * @param string $provider The AI provider (e.g., 'fal-ai/text-to-image', 'openai', 'stability-ai')
     * @param string $model The model to use (e.g., 'fal-ai/flux-pro/kontext/text-to-image', 'dall-e-3')
     * @param string $prompt The text prompt for image generation
     * @return array The API response containing image data
     */
    public function generateImage(string $provider, string $model, string $prompt, $params = []): array
    {
        // Extract the base provider from the provider string (e.g., 'fal-ai' from 'fal-ai/text-to-image')
        $baseProvider = explode('/', $provider)[0];

        if (empty($baseProvider)) {
            $baseProvider = 'fal-ai'; // Default to fal-ai if no provider is specified
            $model = 'ideogram/v2';
        }

        #$endpoint = "/{$this->apiEnv}/image/{$baseProvider}/{$key}";
        $endpoint = "/{$this->apiEnv}/image/{$baseProvider}";

        $data = [
            'model' => $model, // Use the full model path directly
            'prompt' => $prompt,
        ];

        if (! empty($params)) {
            $data = array_merge($data, $params);
        }

        return $this->post($endpoint, $data);
    }

    /**
     * Generate an image using the fal-ai/ideogram/v2 model (convenience method).
     *
     * @param string $prompt The text prompt for image generation
     * @return array The API response containing image data
     */
    public function generateImageWithIdeogram(string $prompt): array
    {
        return $this->generateImage('fal-ai', 'ideogram/v2', $prompt);
    }

    /**
     * Generate an image using OpenAI DALL-E (convenience method).
     *
     * @param string $prompt The text prompt for image generation
     * @param string $model The DALL-E model version (default: 'dall-e-3')
     * @return array The API response containing image data
     */
    public function generateImageWithDallE(string $prompt, string $model = 'dall-e-3'): array
    {
        return $this->generateImage('openai', $model, $prompt);
    }

    /**
     * Generate an image using Stability AI (convenience method).
     *
     * @param string $prompt The text prompt for image generation
     * @param string $model The Stability AI model (default: 'stable-diffusion-xl')
     * @return array The API response containing image data
     */
    public function generateImageWithStabilityAI(string $prompt, string $model = 'stable-diffusion-xl'): array
    {
        return $this->generateImage('stability-ai', $model, $prompt);
    }

    /**
     * Start a new image chat conversation.
     * This method initiates a new conversation and generates the first image using the text-to-image endpoint.
     *
     * @param string $prompt The initial text prompt for image generation
     * @param string $model The model to use (default: 'fal-ai/flux-1/dev')
     * @param array $additionalParams Additional parameters for image generation
     * @return array The API response containing image data and metadata
     */
    public function startImageChat(
        string $prompt,
        string $model = 'fal-ai/flux-1/dev',
        array $additionalParams = []
    ): array {
        $endpoint = "/{$this->apiEnv}/image/fal-ai/text-to-image";

        $data = [
            'model' => $model,
            'prompt' => $prompt,
        ];

        // Merge any additional parameters
        if (! empty($additionalParams)) {
            $data = array_merge($data, $additionalParams);
        }

        $response = $this->post($endpoint, $data);

        // Extract the image URL from the response
        $imageUrl = $this->extractImageUrl($response);

        return [
            'image_response' => $response,
            'prompt_history' => [$prompt],
            'image_url' => $imageUrl,
        ];
    }

    /**
     * Continue an existing image chat conversation with context.
     * This method edits the previous image based on conversation history.
     *
     * @param string $previousImageUrl The URL of the last generated image
     * @param array $previousPrompts Array of all previous prompts in chronological order
     * @param string $newPrompt The new prompt/instruction from the user
     * @param string $model The model to use (default: 'fal-ai/flux-kontext/dev')
     * @param bool $subscribe Whether to subscribe for real-time updates
     * @param array $additionalParams Additional parameters for the API
     * @return array The API response containing the new image and updated context
     */
    public function continueImageChat(
        string $previousImageUrl,
        array $previousPrompts,
        string $newPrompt,
        string $model = 'fal-ai/flux-kontext/dev',
        bool $subscribe = true,
        array $additionalParams = []
    ): array {
        $endpoint = "/{$this->apiEnv}/image/fal-ai/image-chat";

        $data = [
            'operation' => 'submit',
            'previousImageUrl' => $previousImageUrl,
            'previousPrompts' => $previousPrompts,
            'model' => $model,
            'prompt' => $newPrompt,
            'subscribe' => $subscribe,
        ];

        // Merge any additional parameters
        if (! empty($additionalParams)) {
            $data = array_merge($data, $additionalParams);
        }

        $response = $this->post($endpoint, $data);

        // Extract the new image URL from the nested structure
        $newImageUrl = null;
        if (isset($response['fal']['data']['images'][0]['url'])) {
            $newImageUrl = $response['fal']['data']['images'][0]['url'];
        }

        // Build updated prompt history
        $updatedPromptHistory = array_merge($previousPrompts, [$newPrompt]);

        return [
            'image_response' => $response,
            'prompt_history' => $updatedPromptHistory,
            'image_url' => $newImageUrl,
            'context_summary' => $response['context_summary'] ?? null,
        ];
    }

    /**
     * Extract the image URL from an image-chat API response.
     * This handles the nested structure of the image-chat endpoint response.
     *
     * @param array $response The API response from image-chat methods
     * @return string|null The image URL or null if not found
     */
    public function extractImageChatUrl(array $response): ?string
    {
        // Handle the nested structure: fal.data.images[0].url
        if (isset($response['images'][0]['url'])) {
            return $response['images'][0]['url'];
        }

        // Fallback to direct image_url if already extracted
        if (isset($response['image_url'])) {
            return $response['image_url'];
        }

        return null;
    }

    /**
     * Extract full image metadata from an image-chat API response.
     *
     * @param array $response The API response from image-chat methods
     * @return array|null The image metadata or null if not found
     */
    public function extractImageChatMetadata(array $response): ?array
    {
        if (isset($response['fal']['data']['images'][0])) {
            return $response['fal']['data']['images'][0];
        }

        return null;
    }

    /**
     * Get the complete prompt history from an image-chat response.
     *
     * @param array $response The API response
     * @return array Array of prompts in chronological order
     */
    public function getImageChatPromptHistory(array $response): array
    {
        if (isset($response['prompt_history'])) {
            return $response['prompt_history'];
        }

        return [];
    }

    /**
     * Convenience method to start an image chat with Flux 1 Dev model.
     *
     * @param string $prompt The initial prompt
     * @param array $params Additional parameters
     * @return array Response with image data and prompt history
     */
    public function startImageChatWithFlux(string $prompt, array $params = []): array
    {
        return $this->startImageChat($prompt, 'fal-ai/flux-1/dev', $params);
    }

    /**
     * Perform a GET request to the API.
     */
    public function get(string $endpoint): array
    {
        try {
            $response = $this->client->get($endpoint);
            $body = $response->getBody()->getContents();

            return json_decode($body, true);
        } catch (ClientException $e) {
            throw $e;
        }
    }

    /**
     * Perform a POST request to the API.
     */
    public function post(string $endpoint, array $data, array $queryParams = []): array
    {
        try {
            $options = [
                'json' => $data,
            ];

            if (! empty($queryParams)) {
                $options['query'] = $queryParams;
            }

            $response = $this->client->post($endpoint, $options);
            $body = $response->getBody()->getContents();

            return json_decode($body, true);
        } catch (ClientException $e) {
            throw $e;
        }
    }

    /**
     * Extract the image URL from the API response.
     *
     * @param array $response The API response
     * @return string|null The image URL or null if not found
     */
    public function extractImageUrl(array $response): ?string
    {
        // Based on the example response structure: { "0": { "url": "...", ... } }
        if (isset($response['0']['url'])) {
            return $response['0']['url'];
        }

        return null;
    }

    /**
     * Extract image metadata from the API response.
     *
     * @param array $response The API response
     * @return array|null The image metadata or null if not found
     */
    public function extractImageMetadata(array $response): ?array
    {
        if (isset($response['0'])) {
            return $response['0'];
        }

        return null;
    }
}
