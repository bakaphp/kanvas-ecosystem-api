<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Laravel;

use Illuminate\Database\Eloquent\Model;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Intelligence\Agents\Models\Agent as AgentRecord;
use Kanvas\Intelligence\Agents\Models\AgentHistory;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Promptable;
use Laravel\Ai\Responses\AgentResponse;
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
    ): void {
        $this->agentRecord = $agent;
        $this->entity = $entity;
        $this->app = $agent->app;
        $this->company = $agent->company;
        $this->externalReferenceId = $externalReferenceId;
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

    public function promptWithConfig(string $message): AgentResponse
    {
        return $this->prompt(
            $message,
            provider: $this->getProvider(),
            model: $this->getModel(),
            timeout: $this->agentRecord?->config['timeout'] ?? 120,
        );
    }

    abstract public function instructions(): Stringable|string;

    abstract public function tools(): iterable;
}
