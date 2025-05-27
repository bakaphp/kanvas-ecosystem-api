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
     * Generate an image using text-to-image model.
     *
     * @param string $provider The AI provider (e.g., 'fal-ai', 'openai', 'stability-ai')
     * @param string $model The model to use (e.g., 'ideogram/v2', 'dall-e-3')
     * @param string $prompt The text prompt for image generation
     * @param string $key The API endpoint identifier (default: 'text-to-image')
     * @return array The API response containing image data
     */
    public function generateImage(string $provider, string $model, string $prompt, string $key = 'text-to-image'): array
    {
        $endpoint = "/{$this->apiEnv}/image/{$provider}/{$key}";

        $data = [
            'model' => $provider . '/' . $model,
            'prompt' => $prompt,
        ];

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
    public function post(string $endpoint, array $data): array
    {
        try {
            $response = $this->client->post($endpoint, [
                'json' => $data,
            ]);
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
