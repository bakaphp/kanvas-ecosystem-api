<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Laravel;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Config;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Intelligence\Agents\Laravel\Contracts\KanvasToolInterface;
use Kanvas\Intelligence\Agents\Models\Agent as AgentRecord;
use Kanvas\Intelligence\Agents\Models\AgentHistory;
use Kanvas\Intelligence\Enums\ConfigurationEnum;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
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
        // Request-scoped app/company take precedence over the agent's own values,
        // which may be null or id=0 for global agents.
        $this->app = $app ?? $agent->app;
        $this->company = $company ?? $agent->company;
        $this->externalReferenceId = $externalReferenceId;
    }

    /**
     * Surfaces the Kanvas Agent id to consumers that only see the Laravel AI
     * agent instance (e.g. KanvasConversationStore via $prompt->agent on the
     * RememberConversation middleware path). Laravel AI's ConversationStore
     * interface doesn't pass the agent into storeConversation, so this is the
     * bridge that lets us wire conversation rows back to their Kanvas Agent.
     */
    public function getKanvasAgentId(): ?int
    {
        return $this->agentRecord?->getId();
    }

    protected function getProvider(): ?Lab
    {
        $provider = $this->agentRecord?->model?->config['provider'] ?? null;

        return match ($provider) {
            'anthropic' => Lab::Anthropic,
            'openai' => Lab::OpenAI,
            'gemini' => Lab::Gemini,
            'groq' => Lab::Groq,
            'mistral' => Lab::Mistral,
            'ollama' => Lab::Ollama,
            'deepseek' => Lab::DeepSeek,
            default => null,
        };
    }

    protected function getModel(): ?string
    {
        return $this->agentRecord?->model?->config['model'] ?? null;
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

    #[Override]
    final public function tools(): iterable
    {
        $tools = [];

        foreach ($this->agentTools() as $tool) {
            if (($tool instanceof KanvasToolInterface || $tool instanceof KanvasAgentAsTool)
                && $this->app && $this->company) {
                $tool->withContext($this->app, $this->company);
            }
            $tools[] = $tool;
        }

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
