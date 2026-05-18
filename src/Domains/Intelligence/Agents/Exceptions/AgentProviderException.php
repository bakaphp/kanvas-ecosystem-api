<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Exceptions;

use Baka\Exceptions\LightHouseCustomException;
use GuzzleHttp\Exception\BadResponseException;
use Illuminate\Http\Client\RequestException as LaravelRequestException;
use Kanvas\Intelligence\Agents\Models\Agent;
use Override;
use Throwable;

class AgentProviderException extends LightHouseCustomException
{
    public function __construct(
        string $message,
        private readonly ?string $provider = null,
        private readonly ?int $status = null,
        private readonly ?int $agentId = null,
    ) {
        parent::__construct($message);
    }

    public static function fromThrowable(Throwable $e, ?Agent $agent = null): Throwable
    {
        $upstream = self::findUpstream($e);
        if ($upstream === null) {
            return $e;
        }

        $providerMessage = self::extractProviderMessage($upstream['body']) ?? $upstream['fallback_message'];
        $provider = self::detectProvider($upstream['host']);

        // Keep the full upstream trace in Sentry/logs even though the client
        // only sees the clean parsed message.
        report($e);

        return new self(
            message: $providerMessage,
            provider: $provider,
            status: $upstream['status'],
            agentId: $agent?->getId(),
        );
    }

    /**
     * Walk the cause chain looking for either a Guzzle BadResponseException
     * (Neuron path) or an Illuminate\Http\Client\RequestException (Laravel-AI
     * path) and return a normalized {host, status, body, fallback_message}.
     *
     * @return array{host: string, status: int, body: string, fallback_message: string}|null
     */
    private static function findUpstream(Throwable $e): ?array
    {
        $current = $e;
        while ($current !== null) {
            if ($current instanceof BadResponseException) {
                $response = $current->getResponse();

                return [
                    'host' => $current->getRequest()->getUri()->getHost(),
                    'status' => $response->getStatusCode(),
                    'body' => (string) $response->getBody(),
                    'fallback_message' => $current->getMessage(),
                ];
            }

            if ($current instanceof LaravelRequestException) {
                $response = $current->response;

                return [
                    'host' => $response->effectiveUri()?->getHost() ?? '',
                    'status' => $response->status(),
                    'body' => $response->body(),
                    'fallback_message' => $current->getMessage(),
                ];
            }

            $current = $current->getPrevious();
        }

        return null;
    }

    private static function extractProviderMessage(string $body): ?string
    {
        if ($body === '') {
            return null;
        }
        $decoded = json_decode($body, true);
        if (! is_array($decoded)) {
            return null;
        }

        // Each provider nests the message slightly differently — pull what's there.
        if (isset($decoded['error']['message']) && is_string($decoded['error']['message'])) {
            return $decoded['error']['message'];
        }
        if (isset($decoded['error']) && is_string($decoded['error'])) {
            return $decoded['error'];
        }
        if (isset($decoded['message']) && is_string($decoded['message'])) {
            return $decoded['message'];
        }

        return null;
    }

    private static function detectProvider(string $host): ?string
    {
        return match (true) {
            str_contains($host, 'googleapis.com') => 'gemini',
            str_contains($host, 'openai.com') => 'openai',
            str_contains($host, 'anthropic.com') => 'anthropic',
            str_contains($host, 'groq.com') => 'groq',
            str_contains($host, 'mistral.ai') => 'mistral',
            str_contains($host, 'deepseek.com') => 'deepseek',
            str_contains($host, 'ollama') => 'ollama',
            default => null,
        };
    }

    #[Override]
    public function getExtensions(): array
    {
        return [
            'category' => 'agent_provider_error',
            'provider' => $this->provider,
            'status' => $this->status,
            'agent_id' => $this->agentId,
        ];
    }
}
