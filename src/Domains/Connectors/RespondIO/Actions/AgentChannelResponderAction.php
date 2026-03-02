<?php

declare(strict_types=1);

namespace Kanvas\Connectors\RespondIO\Actions;

use Inspector\Configuration;
use Inspector\Inspector;
use Kanvas\Connectors\RespondIO\Client;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\Agents\Actions\BaseAgentResponderAction;
use Kanvas\Intelligence\Agents\Helpers\ChatHelper;
use Kanvas\Intelligence\Agents\Types\ADKAgent;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Observability\InspectorObserver;
use Override;

class AgentChannelResponderAction extends BaseAgentResponderAction
{
    protected string $messageTypeVerb = 'respondio-text';
    protected string $communicationChannel = 'respondio';

    #[Override]
    public function execute(array $params = []): array
    {
        $messageConversation = $this->message->message['content'] ?? null;

        if ($messageConversation === null) {
            throw new ValidationException('No conversation found');
        }

        if ($this->message->entity() === null) {
            throw new ValidationException('No entity found');
        }

        $phone = $this->hijackMessagePhone($this->message->message['chat_jid']);

        $useInspector = $this->message->app->get('inspector-key') !== null;

        $currentAgent = new $this->agent->type->handler();

        $currentAgent->setConfiguration(
            $this->agent,
            $this->message->entity()->people
        );

        if ($useInspector) {
            $inspector = new Inspector(
                new Configuration($this->message->app->get('inspector-key'))
            );
            $currentAgent->observe(
                new InspectorObserver($inspector)
            );
        }

        $respondIOClient = new Client(
            $this->message->app,
            $this->message->company
        );

        $onChunk = function ($text, $data) use ($respondIOClient, $phone): void {
            $response = $this->createMessage($text, $phone, $this->message, $this->channel);
            if (! $response->is_locked) {
                $respondIOClient->sendMessage($phone, $text);
            }
        };

        $question = $currentAgent instanceof ADKAgent ?
            $currentAgent->chat(
                $this->channel,
                $this->message,
                $messageConversation,
                $onChunk,
                $this->session
            ) : $currentAgent->chat(new UserMessage($messageConversation));

        $responseContent = $question->getContent();

        $responseText = ChatHelper::extractTextFromResponse($responseContent);

        if (! ($currentAgent instanceof ADKAgent)) {
            $response = $this->createMessage($responseText, $phone, $this->message, $this->channel);
            if (! $response->is_locked) {
                $respondIOClient->sendMessage($phone, $responseText);
            }
        }

        return [
            'message' => $messageConversation,
            'responseText' => $responseContent,
            'response' => $responseText,
        ];
    }
}
