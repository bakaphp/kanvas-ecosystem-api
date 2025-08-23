<?php

declare(strict_types=1);

namespace App\Console\Commands\Intelligence;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Inspector\Configuration;
use Inspector\Inspector;
use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Intelligence\Agents\Helpers\ChatHelper;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Types\ADKAgent;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Messages\Actions\CreateMessageAction;
use Kanvas\Social\Messages\DataTransferObject\MessageInput;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\MessagesTypes\Actions\CreateMessageTypeAction;
use Kanvas\Social\MessagesTypes\DataTransferObject\MessageTypeInput;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Observability\AgentMonitoring;

class KanvasAgentCommand extends Command
{
    use KanvasJobsTrait;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'kanvas:agent {app_id} {agent_id} {namespace} {entity_id} {--interactive : Start an interactive chat session}';

    /**
     * The console command description.
     *
     * @var string|null
     */
    protected $description = 'Interact with a Kanvas agent';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->newLine();
        $this->info('Kanvas Agent');
        $this->newLine();

        $appId = (int) $this->argument('app_id');
        $app = Apps::getById($appId);
        $this->overwriteAppService($app);
        $agentId = (int) $this->argument('agent_id');
        $agent = Agent::getById($agentId, $app);

        // Initialize the agent
        $crm = new $agent->type->handler();
        $inspector = new Inspector(
            new Configuration($app->get('inspector-key'))
        );

        // Assuming the People model is correctly set up
        //$person = People::getById(57626); // You might want to make this configurable
        $entity = $this->argument('entity_id');
        $namespace = $this->argument('namespace');
        $entity = $namespace::getById($entity);
        $crm->setConfiguration($agent, $entity);

        /* $crm->observe(
            new AgentMonitoring($inspector)
        ); */

        if ($this->option('interactive')) {
            $this->startInteractiveChat($crm, $entity);
        } else {
            // Handle single question mode for backward compatibility
            $question = $this->ask('What would you like to ask the agent?');
            $response = $crm->chat(new UserMessage($question));
            $this->info(ChatHelper::extractTextFromResponse($response->getContent()));
        }
    }

    /**
     * Start an interactive chat session with the agent.
     */
    protected function startInteractiveChat(object $agent, Model $entity): void
    {
        $this->info("Interactive chat session started. Type 'exit' or 'quit' to end the conversation.");
        $chatHistory = [];

        while (true) {
            $question = $this->ask('You');

            // Check if user wants to exit
            if ($question !== null && strtolower($question) === 'exit' || strtolower($question) === 'quit') {
                $this->info('Chat session ended.');

                break;
            }

            $channel = new Channel();
            $channel->name = 'Kanvas Agent Channel';
            $channel->slug = $entity->uuid;
            $channel->companies_id = $entity->companies_id;
            $channel->apps_id = $entity->apps_id;
            $channel->description = 'Channel for Kanvas Agent interactions';
            $channel->save();
            $messageType = 'kanvas-agent-message';
            $messageTypeModel = (new CreateMessageTypeAction(
                new MessageTypeInput(
                    $entity->app->getId(),
                    0,
                    $messageType,
                    $messageType,
                )
            ))->execute();

            // Create the message using the action
            $messageInput = new MessageInput(
                app: $entity->app,
                company: $entity->company,
                user: $entity->user,
                type: $messageTypeModel,
                message: [
                ],
                is_public: 1,
                slug: $entity->uuid,
            );

            $createMessageAction = new CreateMessageAction($messageInput);
            $message = $createMessageAction->execute();

            // Send message to agent
            $response = $agent instanceof ADKAgent ? $agent->chat($channel, $message, $question) : $agent->chat(new UserMessage($question));

            // Store in chat history if you want to implement context
            $chatHistory[] = [
                'role' => 'user',
                'content' => $question,
            ];
            $chatHistory[] = [
                'role' => 'assistant',
                'content' => $response->getContent(),
            ];

            // Display agent's response
            $this->newLine();
            Log::info('Agent response: ' . $response->getContent());
            $this->info('Agent: ' . ChatHelper::extractTextFromResponse($response->getContent()));
            $this->newLine();
        }
    }
}
