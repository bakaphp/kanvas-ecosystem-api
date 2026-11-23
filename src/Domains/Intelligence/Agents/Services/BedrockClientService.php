<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Services;

use Aws\BedrockRuntime\BedrockRuntimeClient;

/**
 * Neuron's Bedrock provider talks through the AWS SDK instead of the Guzzle client every other
 * provider shares, so the turn timeout and the retry budget of LlmHttpRetryService are restated here.
 */
class BedrockClientService
{
    public const string DEFAULT_REGION = 'us-east-1';
    public const string DEFAULT_MODEL = 'global.anthropic.claude-haiku-5-5';
    public const string DEFAULT_FALLBACK_MODEL = 'global.amazon.nova-2-lite-v1:0';

    public static function make(string $key, ?string $region = null): BedrockRuntimeClient
    {
        return new BedrockRuntimeClient([
            'region' => $region ?? self::region(),
            'version' => '2023-09-30',
            'token' => ['token' => $key],
            'auth_scheme_preference' => ['smithy.api#httpBearerAuth'],
            'http' => [
                'timeout' => 220,
                'connect_timeout' => 30,
            ],
            'retries' => [
                'mode' => 'standard',
                'max_attempts' => LlmHttpRetryService::MAX_RETRIES + 1,
            ],
        ]);
    }

    public static function region(): string
    {
        return (string) (config('ai.providers.bedrock.region') ?: self::DEFAULT_REGION);
    }

    public static function defaultModel(): string
    {
        return (string) (config('ai.providers.bedrock.models.text.default') ?: self::DEFAULT_MODEL);
    }

    public static function fallbackModel(): ?string
    {
        $model = config('ai.providers.bedrock.models.text.fallback');

        return is_string($model) && $model !== '' ? $model : null;
    }

    public static function platformKey(): ?string
    {
        $key = config('ai.providers.bedrock.key');

        return is_string($key) && $key !== '' ? $key : null;
    }
}
