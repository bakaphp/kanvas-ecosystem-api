<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Laravel;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Config;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Intelligence\Agents\Enums\AgentLlmProviderEnum;
use Kanvas\Intelligence\Agents\Laravel\Contracts\KanvasToolInterface;
use Kanvas\Intelligence\Agents\Laravel\Tools\Common\CurrentTimeTool;
use Kanvas\Intelligence\Agents\Models\Agent as AgentRecord;
use Kanvas\Intelligence\Agents\Models\AgentHistory;
use Kanvas\Intelligence\Agents\Models\AgentLlmConfig;
use Kanvas\Intelligence\Agents\Services\AgentProviderService;
use Kanvas\Intelligence\Agents\Traits\HasEntityContext;
use Kanvas\Intelligence\Enums\ConfigurationEnum;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Files\File;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Promptable;
use Laravel\Ai\Responses\AgentResponse;
use Override;
use Stringable;

abstract class KanvasLaravelAgent implements Agent, Conversational, HasTools
{
    use Promptable;

    protected ?AgentRecord $agentRecord = null;
    protected ?Apps $app = null;
    protected ?Companies $company = null;
    protected ?Model $entity = null;
    protected ?string $externalReferenceId = null;

    public function setConfiguration(
        AgentRecord $agent,
        ?Model $entity = null,
        ?string $externalReferenceId = null,
        ?Apps $app = null,
        ?Companies $company = null,
    ): void {
        $this->agentRecord = $agent;
        $this->entity = $entity;
        $this->app = $app ?? $agent->app;
        $this->company = $company ?? $agent->company;
        $this->externalReferenceId = $externalReferenceId;
    }

    public function getKanvasAgentId(): ?int
    {
        return $this->agentRecord?->getId();
    }

    protected function getProvider(): ?Lab
    {
        $selected = $this->selectedLlmConfig();
        if ($selected !== null) {
            return self::labForProvider($selected->providerEnum());
        }

        // groq is the one laravel-ai provider AgentLlmProviderEnum has no case for.
        $provider = $this->agentRecord?->model?->config['provider'] ?? null;
        if ($provider === 'groq') {
            return Lab::Groq;
        }

        $enum = AgentLlmProviderEnum::tryFrom((string) $provider);
        if ($enum !== null) {
            return self::labForProvider($enum);
        }

        // Fall back to the app default rather than null — a null provider leaves the
        // agent with a key but nothing to call, and it replies empty.
        return $this->agentRecord !== null
            ? self::labForProvider(AgentProviderService::resolveProviderEnum($this->agentRecord))
            : null;
    }

    protected function getModel(): ?string
    {
        return $this->selectedLlmConfig()?->model
            ?? $this->agentRecord?->model?->config['model']
            ?? ($this->agentRecord !== null ? AgentProviderService::resolveModel($this->agentRecord) : null);
    }

    private function selectedLlmConfig(): ?AgentLlmConfig
    {
        return $this->agentRecord !== null
            ? AgentProviderService::selectedConfig($this->agentRecord)
            : null;
    }

    /**
     * Map the runtime-agnostic provider enum to a laravel-ai Lab. OPENAI_LIKE → OpenAICompatible,
     * whose url + key we inject per-tenant in applyTenantProviderCredentials().
     */
    private static function labForProvider(AgentLlmProviderEnum $provider): Lab
    {
        return match ($provider) {
            AgentLlmProviderEnum::GEMINI => Lab::Gemini,
            AgentLlmProviderEnum::ANTHROPIC => Lab::Anthropic,
            AgentLlmProviderEnum::OPENAI => Lab::OpenAI,
            AgentLlmProviderEnum::OPENAI_LIKE => Lab::OpenAICompatible,
            AgentLlmProviderEnum::MISTRAL => Lab::Mistral,
            AgentLlmProviderEnum::DEEPSEEK => Lab::DeepSeek,
            AgentLlmProviderEnum::XAI => Lab::xAI,
            AgentLlmProviderEnum::OLLAMA => Lab::Ollama,
        };
    }

    #[Override]
    public function messages(): iterable
    {
        if (! $this->entity || ! $this->agentRecord) {
            return [];
        }

        return AgentHistory::where('agent_id', $this->agentRecord->getId())
            ->where('entity_namespace', get_class($this->entity))
            ->where('entity_id', $this->entity->getId())
            ->notDeleted()
            ->latest()
            ->limit(50)
            ->get()
            ->reverse()
            ->flatMap(function (AgentHistory $history) {
                $messages = [];

                if ($history->input) {
                    $messages[] = new Message(
                        $history->input['role'] ?? 'user',
                        $history->input['content'] ?? ''
                    );
                }

                if ($history->output) {
                    $messages[] = new Message(
                        $history->output['role'] ?? 'assistant',
                        $history->output['content'] ?? ''
                    );
                }

                return $messages;
            })
            ->all();
    }

    /**
     * @param list<File> $attachments Multimodal inputs (images) for THIS turn — passed straight
     *                                through to laravel-ai's UserMessage so the model can see them.
     */
    public function promptWithConfig(string $message, array $attachments = []): AgentResponse
    {
        // Snapshot → override → restore in finally so the per-tenant key only
        // lives in global config for the duration of THIS prompt call. Under
        // Octane the config repository is a singleton shared across requests
        // on the same worker; without the restore the previous tenant's key
        // would leak into anything that reads `ai.providers.*.key` later.
        $restore = $this->applyTenantProviderCredentials();

        try {
            return $this->prompt(
                $message,
                attachments: $attachments,
                provider: $this->getProvider(),
                model: $this->getModel(),
                timeout: $this->agentRecord?->config['timeout'] ?? 120,
            );
        } finally {
            $restore();
        }
    }

    /**
     * Override Laravel-AI's provider keys with the current tenant's values.
     * Returns a closure that restores the original config — call it in finally
     * to guarantee no cross-tenant leak under Octane.
     */
    protected function applyTenantProviderCredentials(): \Closure
    {
        $selected = $this->selectedLlmConfig();
        if ($selected !== null) {
            return $this->applySelectedConfigCredentials($selected);
        }

        $app = $this->app;
        if ($app === null) {
            return static fn () => null;
        }

        // app-config key → laravel-ai config path.
        $bindings = [
            ConfigurationEnum::GEMINI_KEY->value => 'ai.providers.gemini.key',
            // Future per-tenant providers slot in once their
            // ConfigurationEnum cases exist (openai, anthropic, ...).
        ];

        $snapshot = [];
        foreach ($bindings as $appKey => $configPath) {
            $value = $app->get($appKey);
            if ($value === null || $value === '') {
                continue;
            }
            $snapshot[$configPath] = Config::get($configPath);
            Config::set($configPath, $value);
        }

        return static function () use ($snapshot): void {
            foreach ($snapshot as $configPath => $originalValue) {
                Config::set($configPath, $originalValue);
            }
        };
    }

    /**
     * Push the selected LLM config's credentials into laravel-ai's provider config for the duration
     * of one prompt (snapshot → set → restore, same Octane-safe dance as the tenant-key path). The
     * `driver` is set explicitly because getInstanceConfig() only defaults it when the whole provider
     * block is absent — once we set `.key`/`.url` the block exists without a driver and would break.
     */
    private function applySelectedConfigCredentials(AgentLlmConfig $config): \Closure
    {
        $driver = self::labForProvider($config->providerEnum())->value;

        $overrides = ["ai.providers.{$driver}.driver" => $driver];
        if ($config->api_key !== null && $config->api_key !== '') {
            $overrides["ai.providers.{$driver}.key"] = $config->api_key;
        }
        if ($config->base_uri !== null && $config->base_uri !== '') {
            $overrides["ai.providers.{$driver}.url"] = $config->base_uri;
        }

        $snapshot = [];
        foreach ($overrides as $configPath => $value) {
            $snapshot[$configPath] = Config::get($configPath);
            Config::set($configPath, $value);
        }

        return static function () use ($snapshot): void {
            foreach ($snapshot as $configPath => $originalValue) {
                Config::set($configPath, $originalValue);
            }
        };
    }

    #[Override]
    final public function tools(): iterable
    {
        $tools = [];

        foreach ($this->withUniversalTools($this->agentTools()) as $tool) {
            if (($tool instanceof KanvasToolInterface || $tool instanceof KanvasAgentAsTool)
                && $this->app && $this->company) {
                $tool->withContext($this->app, $this->company, $this->agentRecord);
            }
            if (in_array(HasEntityContext::class, class_uses_recursive($tool), true)) {
                $tool->withEntity($this->entity);
            }
            $tools[] = $tool;
        }

        return $tools;
    }

    /**
     * Souls tell the model to call get_current_time, and the runtime errors when the model names a
     * tool the provider was never given (Sentry KANVAS-ECOSYSTEM-600). Time must reach every agent
     * whatever its agentTools() returns. Appending here (rather than in each agentTools()) also means
     * the tool picks up its Kanvas context from the loop above like any other.
     *
     * @param iterable<object> $agentTools
     *
     * @return list<object>
     */
    private function withUniversalTools(iterable $agentTools): array
    {
        $tools = is_array($agentTools)
            ? array_values($agentTools)
            : iterator_to_array($agentTools, false);

        // A one-shot structured-output agent with no tools of its own must stay
        // tool-free: Gemini rejects the whole request when a JSON response mime
        // type is combined with function calling ("Function calling with a
        // response mime type: 'application/json' is unsupported"), so injecting
        // a clock it never asked for makes it unrunnable on that provider.
        if ($tools === [] && $this instanceof HasStructuredOutput) {
            return [];
        }

        foreach ($tools as $tool) {
            if ($tool instanceof CurrentTimeTool) {
                return $tools;
            }
        }

        $tools[] = new CurrentTimeTool();

        return $tools;
    }

    #[Override]
    abstract public function instructions(): Stringable|string;

    abstract public function agentTools(): iterable;

    /**
     * Build instructions from the agent record, falling back to its AgentType
     * template per field, then to a static default if nothing is set in the DB.
     *
     * An agent (or its type) that populates soul/instructions/output_format
     * overrides the in-code prompt, so the same handler class can be re-skinned
     * per type without a code change — mirrors the Neuron backend, which reads
     * its persona from $agent->role.
     */
    protected function instructionsFromRecord(string $default = ''): string
    {
        $type = $this->agentRecord?->type;
        $coalesce = static fn (?string $a, ?string $b): ?string => ($a !== null && $a !== '') ? $a : $b;

        $parts = array_filter([
            $coalesce($this->agentRecord?->soul, $type?->soul),
            $coalesce($this->agentRecord?->instructions, $type?->instructions),
            $coalesce($this->agentRecord?->output_format, $type?->output_format),
        ]);

        return $parts === [] ? $default : implode("\n\n", $parts);
    }
}
