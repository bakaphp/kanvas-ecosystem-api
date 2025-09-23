<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Twilio\Actions;

use Baka\Support\Str;
use Inspector\Configuration;
use Inspector\Inspector;
use Kanvas\Connectors\Twilio\Client;
use Kanvas\Connectors\WaSender\Actions\AgentChannelResponderAction as BaseAgentChannelResponderAction;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\Agents\Helpers\ChatHelper;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Types\ADKAgent;
use Kanvas\Social\Messages\Actions\CreateMessageAction;
use Kanvas\Social\Messages\DataTransferObject\MessageInput;
use Kanvas\Social\Messages\Models\Message;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Observability\AgentMonitoring;
use Override;

class AgentChannelResponderAction extends BaseAgentChannelResponderAction
{
    #[Override]
    public function execute(array $params = []): array
    {
        if ($this->message->entity() === null) {
            throw new ValidationException('No entity found');
        }

        $useInspector = $this->message->app->get('inspector-key') !== null;

        $currentAgent = new $this->agent->type->handler();
        //$currentAgent = $this->agent;

        $currentAgent->setConfiguration(
            $this->agent,
            $this->message->entity()->people
        );

        if ($useInspector) {
            $inspector = new Inspector(
                new Configuration($this->message->app->get('inspector-key'))
            );
            $currentAgent->observe(
                new AgentMonitoring($inspector)
            );
        }

        $to = Str::replace('twilio-', '', $this->channel->slug);
        $to = "+{$to}";
        $to = $this->hijackMessagePhone($to);

        $client = Client::getInstanceByCompany($this->message->company);
        $message = $this->message;
        $channel = $this->channel;
        $onChunk = function ($text, $data) use ($client, $to, $params, $message, $channel): void {
            // Use the Twilio client to send a message

            $messageInput = new MessageInput(
                app: $message->app,
                company: $message->company,
                user: $message->user,
                type: $message->messageType,
                message: [
                        'content' => $text,
                        ],
                is_public: 1,
                tags: [$to],
                slug: Str::slug($text) . '-' . time()
            );

            $createMessageAction = new CreateMessageAction($messageInput);
            $message = $createMessageAction->execute();
            $channel->addMessage($message);

            $client->messages->create(
                $to, // to
                [
                    'from' => $params['from'],
                    'body' => $text,
                ]
            );
        };
        $messageConversation = $this->message->message['content'];
        $question = $currentAgent instanceof ADKAgent ?
                $currentAgent->chat(
                    $this->channel,
                    $this->message,
                    $messageConversation,
                    $onChunk,
                    $this->session
                ) : $currentAgent->chat(new UserMessage($messageConversation));

        $responseContent = $question->getContent();

        // Extract text from response that might be formatted with markdown code blocks
        $responseText = ChatHelper::extractTextFromResponse($responseContent);

        //if its not an ADKAgent, send the response as a text message
        if (! ($currentAgent instanceof ADKAgent)) {
            $onChunk($responseText, []);
        }

        return [
            'message' => $messageConversation,
            'responseText' => $responseContent,
            'response' => $responseText,
        ];
    }
}
