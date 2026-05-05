<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron;

use Illuminate\Database\Eloquent\Model;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Enums\ConfigurationEnum;
use Kanvas\Users\Models\Users;
use NeuronAI\Agent\Agent as NeuronAIAgent;
use NeuronAI\Agent\SystemPrompt;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Providers\Gemini\Gemini;
use NeuronAI\Tools\Tool;
use Override;

class BaseKanvasAgent extends NeuronAIAgent
{
    protected ?Agent $agent = null;
    protected ?Apps $app = null;
    protected ?Companies $company = null;
    protected ?Model $entity = null;
    protected ?string $externalReferenceId = null;
    protected ?Users $user = null;
    protected ?string $threadId = null;

    public function setConfiguration(
        Agent $agent,
        ?Model $entity = null,
        ?string $externalReferenceId = null,
        ?Users $user = null,
    ): void {
        $this->agent = $agent;
        $this->entity = $entity;
        $this->app = $agent->app;
        $this->company = $agent->company;
        $this->externalReferenceId = $externalReferenceId;
        $this->user = $user;
    }

    public function setThreadId(string $threadId): void
    {
        $this->threadId = $threadId;
    }

    #[Override]
    protected function provider(): AIProviderInterface
    {
        return new Gemini(
            key: $this->app->get(ConfigurationEnum::GEMINI_KEY->value),
            model: $this->app->get(ConfigurationEnum::GEMINI_MODEL->value) ?? 'gemini-2.5-pro',
        );
    }

    #[Override]
    public function instructions(): string
    {
        $role = $this->agent->role;

        return new SystemPrompt(
            background: $role['background'],
            steps: $role['steps'],
            output: $role['output'],
        )->__toString();
    }

    // #[Override]
    // protected function chatHistory(): AbstractChatHistory
    // {
    //     if ($this->entity === null) {
    //         throw new RuntimeException(
    //             'Entity information not set. Make sure to call setConfiguration() with a valid entity.'
    //         );
    //     }

    //     return new KanvasChatHistory(
    //         entityClass: get_class($this->entity),
    //         entityId: $this->entity->getKey(),
    //         limit: 20,
    //     );
    // }

    #[Override]
    protected function tools(): array
    {
        /** @psalm-suppress MixedReturnTypeCoercion */
        return [
            Tool::make(
                'get_current_time',
                'Retrieve the current time from the system.',
            )->setCallable(fn () => [
                'time' => date('Y-m-d H:i:s'),
            ]),
        ];
    }
}
