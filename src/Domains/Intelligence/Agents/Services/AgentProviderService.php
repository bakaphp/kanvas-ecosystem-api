<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Services;

use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\Agents\Enums\AgentLlmProviderEnum;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentLlmConfig;
use Kanvas\Intelligence\Agents\Neuron\Providers\KanvasAnthropic;
use Kanvas\Intelligence\Agents\Neuron\Providers\KanvasDeepseek;
use Kanvas\Intelligence\Agents\Neuron\Providers\KanvasGemini;
use Kanvas\Intelligence\Agents\Neuron\Providers\KanvasGrok;
use Kanvas\Intelligence\Agents\Neuron\Providers\KanvasMistral;
use Kanvas\Intelligence\Agents\Neuron\Providers\KanvasOllama;
use Kanvas\Intelligence\Agents\Neuron\Providers\KanvasOpenAI;
use Kanvas\Intelligence\Agents\Neuron\Providers\KanvasOpenAILike;
use Kanvas\Intelligence\Enums\ConfigurationEnum;
use NeuronAI\HttpClient\GuzzleHttpClient;
use NeuronAI\Providers\AIProviderInterface;

class AgentProviderService
{
    private const array KEYED_PROVIDER_CLASSES = [
        AgentLlmProviderEnum::ANTHROPIC->value => KanvasAnthropic::class,
        AgentLlmProviderEnum::OPENAI->value => KanvasOpenAI::class,
        AgentLlmProviderEnum::MISTRAL->value => KanvasMistral::class,
        AgentLlmProviderEnum::DEEPSEEK->value => KanvasDeepseek::class,
        AgentLlmProviderEnum::XAI->value => KanvasGrok::class,
    ];

    public static function resolve(Agent $agent): AIProviderInterface
    {
        $app = $agent->app;
        $source = self::resolveSource($agent);
        $provider = self::providerFrom($source);
        $model = self::modelFrom($source, $agent);
        $parameters = is_array($source['parameters'] ?? null) ? $source['parameters'] : [];
        $httpClient = new GuzzleHttpClient(
            timeout: 220,
            connectTimeout: 220,
            handler: LlmHttpRetryService::handlerStack(),
        );

        if ($provider === AgentLlmProviderEnum::OPENAI_LIKE) {
            return new KanvasOpenAILike(
                baseUri: self::requireBaseUri($source, $agent),
                key: (string) ($source['key'] ?? $app->get(ConfigurationEnum::AI_PROVIDER_KEY->value) ?? ''),
                model: $model,
                parameters: $parameters,
                httpClient: $httpClient,
            );
        }

        // Ollama authenticates by URL, not an API key — base_uri carries the host.
        if ($provider === AgentLlmProviderEnum::OLLAMA) {
            return new KanvasOllama(
                url: self::requireBaseUri($source, $agent),
                model: $model,
                parameters: $parameters,
                httpClient: $httpClient,
            );
        }

        if ($provider === AgentLlmProviderEnum::GEMINI) {
            return new KanvasGemini(
                key: self::requireKey($source, $agent, $provider),
                model: $model,
                parameters: $parameters,
                httpClient: $httpClient,
            );
        }

        $providerClass = self::KEYED_PROVIDER_CLASSES[$provider->value];

        return new $providerClass(
            key: self::requireKey($source, $agent, $provider),
            model: $model,
            parameters: $parameters,
            httpClient: $httpClient,
        );
    }

    /**
     * The concrete model name the agent will call, following the same precedence as resolve().
     * Exposed so the chat path records the same model for usage/cost rollups.
     */
    public static function resolveModel(Agent $agent): string
    {
        return self::modelFrom(self::resolveSource($agent), $agent);
    }

    /**
     * The provider an agent resolves to, defaulting to the app/Gemini fallback
     * when nothing explicit is configured. Shared so the Laravel runtime falls
     * back the same way this (Neuron) service does instead of resolving to null.
     */
    public static function resolveProviderEnum(Agent $agent): AgentLlmProviderEnum
    {
        return self::providerFrom(self::resolveSource($agent));
    }

    /**
     * Pick the highest-precedence provider source and normalize it to
     * ['provider', 'base_uri', 'key', 'model', 'parameters'].
     *
     * @return array{provider: ?string, base_uri: ?string, key: ?string, model: ?string, parameters: array}
     */
    private static function resolveSource(Agent $agent): array
    {
        $config = $agent->config ?? [];

        $selected = self::selectedConfig($agent);
        if ($selected !== null) {
            return [
                'provider' => $selected->provider,
                'base_uri' => $selected->base_uri,
                'key' => $selected->api_key,
                'model' => $selected->model,
                'parameters' => is_array($selected->config) ? $selected->config : [],
            ];
        }

        if (isset($config['llm_provider'])) {
            return [
                'provider' => (string) $config['llm_provider'],
                'base_uri' => $config['base_uri'] ?? null,
                'key' => $config['key'] ?? null,
                'model' => $config['model'] ?? null,
                'parameters' => is_array($config['parameters'] ?? null) ? $config['parameters'] : [],
            ];
        }

        return [
            'provider' => $agent->app->get(ConfigurationEnum::AI_PROVIDER->value),
            'base_uri' => null,
            'key' => null,
            'model' => $config['model'] ?? null,
            'parameters' => [],
        ];
    }

    public static function selectedConfig(Agent $agent): ?AgentLlmConfig
    {
        if ($agent->agent_llm_config_id === null) {
            return null;
        }

        return AgentLlmConfig::query()
            ->where('id', $agent->agent_llm_config_id)
            ->where('apps_id', $agent->apps_id)
            ->where('companies_id', $agent->companies_id)
            ->where('is_deleted', 0)
            ->where('is_active', 1)
            ->first();
    }

    /**
     * @param array{provider: ?string} $source
     */
    private static function providerFrom(array $source): AgentLlmProviderEnum
    {
        return AgentLlmProviderEnum::tryFrom((string) ($source['provider'] ?? ''))
            ?? AgentLlmProviderEnum::GEMINI;
    }

    /**
     * @param array{model: ?string} $source
     */
    private static function modelFrom(array $source, Agent $agent): string
    {
        $app = $agent->app;

        return (string) ($source['model']
            ?? $app->get(ConfigurationEnum::AI_PROVIDER_MODEL->value)
            ?? $app->get(ConfigurationEnum::GEMINI_MODEL->value)
            ?? 'gemini-3.7-flash');
    }

    /**
     * @param array{base_uri: ?string} $source
     */
    private static function requireBaseUri(array $source, Agent $agent): string
    {
        $baseUri = $source['base_uri']
            ?? $agent->app->get(ConfigurationEnum::AI_PROVIDER_BASE_URI->value);

        if (! is_string($baseUri) || $baseUri === '') {
            throw new ValidationException(
                'OpenAI-compatible provider requires a base URI. Set it on the selected LLM config,'
                . ' agent.config.base_uri, or the app '
                . ConfigurationEnum::AI_PROVIDER_BASE_URI->value . ' setting (e.g. https://your-box/v1).'
            );
        }

        return $baseUri;
    }

    /**
     * @param array{key: ?string} $source
     */
    private static function requireKey(array $source, Agent $agent, AgentLlmProviderEnum $provider): string
    {
        $appKeySetting = $provider === AgentLlmProviderEnum::GEMINI
            ? ConfigurationEnum::GEMINI_KEY
            : ConfigurationEnum::AI_PROVIDER_KEY;

        $key = $source['key'] ?? $agent->app->get($appKeySetting->value);

        if (! is_string($key) || $key === '') {
            throw new ValidationException(
                $provider->value . ' API key is not configured for this agent or app.'
                . ' Set it on the selected LLM config, agent.config.key, or the app '
                . $appKeySetting->value . ' setting.'
            );
        }

        return $key;
    }
}
