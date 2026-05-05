<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\CRM;

use Illuminate\Support\Facades\Blade;
use Kanvas\Intelligence\Agents\Neuron\BaseKanvasAgent;
use Kanvas\Intelligence\Agents\Neuron\KanvasMessageHistory;
use NeuronAI\Agent\SystemPrompt;
use NeuronAI\Chat\History\AbstractChatHistory;
use NeuronAI\Chat\History\InMemoryChatHistory;
use Override;

class IntelligenceCRM extends BaseKanvasAgent
{
    // protected function provider(): AIProviderInterface
    // {
    //     return new Ollama(
    //         url: 'http://198.19.249.3:11434/api',
    //         model: 'qwen3.6:35b',
    //         parameters: [], // Add custom params (temperature, logprobs, etc)
    //     );
    // }

    protected function chatHistory(): AbstractChatHistory
    {
        if ($this->entity === null || $this->user === null) {
            return new InMemoryChatHistory();
        }

        return new KanvasMessageHistory(
            app: $this->app,
            company: $this->company,
            user: $this->user,
            entity: $this->entity,
            threadId: $this->threadId,
        );
    }
    #[Override]
    public function instructions(): string
    {
        $role = $this->agent->role;

        $background = Blade::render($role['background'], ['lead' => $this->entity]);
        $steps = Blade::render($role['steps'], ['lead' => $this->entity]);
        $output = Blade::render($role['output'], ['lead' => $this->entity]);
        $background = explode('\n', $background);

        return new SystemPrompt(
            background: [...$background, "lead_id: {$this->entity->getId()}"],
            steps: explode('\n', $steps),
            output: explode('\n', $output),
        )->__toString();
    }

    #[Override]
    protected function tools(): array
    {
        return $this->agent
            ->tools()
            ->where('is_active', 1)
            ->get()
            ->map(function ($tool) {
                $toolClass = $tool->model_name;

                return new $toolClass($this->entity);
            })
            ->toArray();
    }
}
