<?php

declare(strict_types=1);

namespace App\Console\Commands\Intelligence\Agents;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Kanvas\Apps\Models\Apps;
use Kanvas\Intelligence\Agents\Actions\Chat\AgentChatKernel;
use Kanvas\Intelligence\Agents\Helpers\ChatHelper;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Neuron\Contracts\BehavesAsKanvasAgent;
use Kanvas\Intelligence\Agents\Neuron\Factories\NeuronAgentFactory;
use Kanvas\Intelligence\Agents\Types\ADKAgent;
use NeuronAI\Chat\Messages\UserMessage;

class KanvasAgentCommand extends Command
{
    use KanvasJobsTrait;

    /**
     * The name and signature of the console command.
     *
     * @var string
    */
    protected $signature = 'kanvas:agent {app_id} {agent_id} {namespace} {entity_id}
                           {--interactive : Start an interactive chat session}';

    /**
     * The console command description.
     *
     * @var string|null
     */
    protected $description = 'Interact with a Kanvas agent';

    public function handle(): int
    {
        $this->newLine();
        $this->info('Kanvas Agent');
        $this->newLine();

        $app = Apps::getById((int) $this->argument('app_id'));
        $this->overwriteAppService($app);
        $agent = Agent::getById((int) $this->argument('agent_id'), $app);

        $namespace = (string) $this->argument('namespace');
        $entity = $namespace::getById($this->argument('entity_id'));

        $handler = $this->handlerFor($agent, $entity);

        if ($this->option('interactive')) {
            $this->info("Interactive chat session started. Type 'exit' or 'quit' to end the conversation.");

            while (true) {
                $question = (string) $this->ask('You');

                if (in_array(strtolower($question), ['exit', 'quit'], true)) {
                    $this->info('Chat session ended.');

                    break;
                }

                $this->newLine();
                $this->info('Agent: ' . $this->answer($handler, $entity, $question));
                $this->newLine();
            }

            return self::SUCCESS;
        }

        $question = (string) $this->ask('What would you like to ask the agent?');
        $this->info($this->answer($handler, $entity, $question));

        return self::SUCCESS;
    }

    /**
     * The entity uuid is the thread, so a CLI session continues the record's own conversation — the same
     * address a channel turn on that record binds.
     */
    private function handlerFor(Agent $agent, Model $entity): BehavesAsKanvasAgent|ADKAgent
    {
        $handlerClass = $agent->type->handler;

        if (is_a($handlerClass, ADKAgent::class, true)) {
            $handler = new $handlerClass();
            $handler->setConfiguration(
                agent: $agent,
                entity: $entity,
                user: $entity->company->getAiAgentUserOrFail(),
            );

            return $handler;
        }

        return NeuronAgentFactory::fromAgent(
            agent: $agent,
            entity: $entity,
            user: $entity->company->getAiAgentUserOrFail(),
            threadId: AgentChatKernel::entityThreadId($entity),
        );
    }

    private function answer(BehavesAsKanvasAgent|ADKAgent $handler, Model $entity, string $question): string
    {
        if ($handler instanceof ADKAgent) {
            $response = $handler->chatSimple(
                app: $entity->app,
                company: $entity->company,
                userId: (string) $entity->users_id,
                sessionId: $entity->uuid,
                message: $question,
            );

            return ChatHelper::extractTextFromResponse($response->getContent());
        }

        $message = $handler->chat(new UserMessage($question))->getMessage();

        return ChatHelper::extractTextFromResponse($message?->getContent() ?? '');
    }
}
