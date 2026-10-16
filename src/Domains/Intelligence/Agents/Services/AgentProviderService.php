<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Services;

use Baka\Support\Str;
use Illuminate\Database\Eloquent\Collection;
use Kanvas\Apps\Models\Apps;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\Agents\Enums\AgentLlmProviderEnum;
use Kanvas\Intelligence\Agents\Enums\AgentRunConfigurationEnum;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentLlmConfig;
use Kanvas\Intelligence\Agents\Neuron\Providers\KanvasAnthropic;
use Kanvas\Intelligence\Agents\Neuron\Providers\KanvasDeepseek;
use Kanvas\Intelligence\Agents\Neuron\Providers\KanvasGemini;
use Kanvas\Intelligence\Agents\Neuron\Providers\KanvasGrok;
use Kanvas\Intelligence\Agents\Neuron\Providers\KanvasMistral;
use Kanvas\Intelligence\Agents\Neuron\Providers\KanvasOllama;
use Kanvas\Intelligence\Agents\Neuron\Providers\KanvasOpenAILike;
use Kanvas\Intelligence\Agents\Neuron\Providers\KanvasOpenAIResponses;
use Kanvas\Intelligence\Enums\ConfigurationEnum;
use NeuronAI\HttpClient\Guzzle\GuzzleHttpClient;
use NeuronAI\Providers\AIProviderInterface;

class AgentProviderService
{
    private const array GEMINI_THINKING_LEVELS = ['minimal', 'low', 'medium', 'high'];

    private const array KEYED_PROVIDER_CLASSES = [
        AgentLlmProviderEnum::ANTHROPIC->value => KanvasAnthropic::class,
        AgentLlmProviderEnum::OPENAI->value => KanvasOpenAIResponses::class,
        AgentLlmProviderEnum::MISTRAL->value => KanvasMistral::class,
        AgentLlmProviderEnum::DEEPSEEK->value => KanvasDeepseek::class,
        AgentLlmProviderEnum::XAI->value => KanvasGrok::class,
    ];

    public static function resolve(Agent $agent): AIProviderInterface
    {
        $source = self::resolveSource($agent);

        return self::makeProvider($agent, $source);
    }

    public static function resolveConfig(Agent $agent, AgentLlmConfig $config): AIProviderInterface
    {
        if ((int) $config->apps_id !== (int) $agent->apps_id
            || ! in_array((int) $config->companies_id, [0, (int) $agent->companies_id], true)
            || ! $config->is_active
            || $config->is_deleted) {
            throw new ValidationException('The LLM config is not active or does not belong to the agent tenant.');
        }

        return self::makeProvider($agent, self::sourceFromConfig($config));
    }

    /**
     * Active alternatives available to this agent. Company configurations win
     * over app-global ones, then creation order is used as the stable order.
     *
     * @return Collection<int, AgentLlmConfig>
     */
    public static function fallbackConfigs(Agent $agent): Collection
    {
        $selectedId = $agent->agent_llm_config_id;

        /** @var Collection<int, AgentLlmConfig> $configs */
        $configs = AgentLlmConfig::query()
            ->where('apps_id', $agent->apps_id)
            ->whereIn('companies_id', [0, $agent->companies_id])
            ->when($selectedId !== null, fn ($query) => $query->where('id', '!=', $selectedId))
            ->where('is_deleted', 0)
            ->where('is_active', 1)
            ->where('is_routing_enabled', 1)
            ->get();

        return $configs->sort(function (AgentLlmConfig $left, AgentLlmConfig $right) use ($agent): int {
            $leftKey = [
                (int) $left->companies_id === (int) $agent->companies_id ? 0 : 1,
                $left->getId(),
            ];
            $rightKey = [
                (int) $right->companies_id === (int) $agent->companies_id ? 0 : 1,
                $right->getId(),
            ];

            return $leftKey <=> $rightKey;
        })->values();
    }

    /**
     * @param array{provider: ?string, base_uri: ?string, key: ?string, model: ?string, parameters: array} $source
     */
    private static function makeProvider(Agent $agent, array $source): AIProviderInterface
    {
        $app = $agent->app;
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
                parameters: self::withGeminiThinking($parameters, $app),
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
     * The provider that writes this agent's history summaries, or null to use the agent's own. A company
     * names one of its LLM configs (AgentRunConfigurationEnum::SUMMARY_LLM_CONFIG); failing that the app
     * names a model (SUMMARY_MODEL, optionally SUMMARY_PROVIDER). A config that no longer resolves falls
     * through rather than failing every summary of the tenant.
     */
    public static function summaryProvider(Agent $agent): ?AIProviderInterface
    {
        $configId = (int) ($agent->company?->get(AgentRunConfigurationEnum::SUMMARY_LLM_CONFIG->value) ?? 0);
        $config = $configId > 0 ? self::activeConfig($agent, $configId, includeGlobal: true) : null;

        if ($config !== null) {
            return self::resolveConfig($agent, $config);
        }

        $model = Str::trimToNull((string) ($agent->app->get(AgentRunConfigurationEnum::SUMMARY_MODEL->value) ?? ''));

        if ($model === null) {
            return null;
        }

        $provider = Str::trimToNull((string) ($agent->app->get(AgentRunConfigurationEnum::SUMMARY_PROVIDER->value) ?? ''))
            ?? self::resolveProviderEnum($agent)->value;

        return self::makeProvider($agent, [
            'provider' => $provider,
            'base_uri' => null,
            'key' => null,
            'model' => $model,
            'parameters' => [],
        ]);
    }

    /**
     * The provider that rewrites a customer message into a search query before retrieval: the agent's
     * own model and credentials with thinking low (the one level every Gemini 3 model accepts). The job
     * is one line of text, and at the default level a thinking model makes the customer wait on it
     * before the agent even starts.
     */
    public static function queryRewriteProvider(Agent $agent): AIProviderInterface
    {
        $source = self::resolveSource($agent);

        if (self::providerFrom($source) === AgentLlmProviderEnum::GEMINI) {
            $source['parameters'] = self::withThinkingConfig(
                is_array($source['parameters'] ?? null) ? $source['parameters'] : [],
                ['thinkingLevel' => 'low'],
            );
        }

        return self::makeProvider($agent, $source);
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
            return self::sourceFromConfig($selected);
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

    /**
     * @return array{provider: ?string, base_uri: ?string, key: ?string, model: ?string, parameters: array}
     */
    private static function sourceFromConfig(AgentLlmConfig $config): array
    {
        return [
            'provider' => $config->provider,
            'base_uri' => $config->base_uri,
            'key' => $config->api_key,
            'model' => $config->model,
            'parameters' => is_array($config->config) ? $config->config : [],
        ];
    }

    public static function selectedConfig(Agent $agent): ?AgentLlmConfig
    {
        if ($agent->agent_llm_config_id === null) {
            return null;
        }

        return self::activeConfig($agent, (int) $agent->agent_llm_config_id, includeGlobal: false);
    }

    private static function activeConfig(Agent $agent, int $configId, bool $includeGlobal): ?AgentLlmConfig
    {
        $companies = $includeGlobal ? [0, $agent->companies_id] : [$agent->companies_id];

        return AgentLlmConfig::query()
            ->where('id', $configId)
            ->where('apps_id', $agent->apps_id)
            ->whereIn('companies_id', $companies)
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
            ?? 'gemini-3.8-flash');
    }

    /**
     * The request body carries `generationConfig.thinkingConfig`; the app setting fills it when the
     * selected config did not.
     *
     * @param array<string, mixed> $parameters
     * @return array<string, mixed>
     */
    private static function withGeminiThinking(array $parameters, Apps $app): array
    {
        if (isset($parameters['generationConfig']['thinkingConfig'])) {
            return $parameters;
        }

        $setting = Str::trimToNull((string) $app->get(AgentRunConfigurationEnum::GEMINI_THINKING->value));

        if ($setting === null) {
            return $parameters;
        }

        $level = strtolower($setting);
        $thinking = match (true) {
            is_numeric($setting) => ['thinkingBudget' => (int) $setting],
            in_array($level, self::GEMINI_THINKING_LEVELS, true) => ['thinkingLevel' => $level],
            default => null,
        };

        if ($thinking === null) {
            return $parameters;
        }

        return self::withThinkingConfig($parameters, $thinking);
    }

    /**
     * @param array<string, mixed> $parameters
     * @param array<string, int|string> $thinking
     * @return array<string, mixed>
     */
    private static function withThinkingConfig(array $parameters, array $thinking): array
    {
        $parameters['generationConfig'] = [...($parameters['generationConfig'] ?? []), 'thinkingConfig' => $thinking];

        return $parameters;
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
