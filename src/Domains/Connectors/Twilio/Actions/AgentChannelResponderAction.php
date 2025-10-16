<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Twilio\Actions;

use Baka\Support\Str;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Inspector\Configuration;
use Inspector\Inspector;
use Kanvas\Connectors\Twilio\Client;
use Kanvas\Connectors\WaSender\Actions\AgentChannelResponderAction as BaseAgentChannelResponderAction;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\Agents\Helpers\ChatHelper;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Types\ADKAgent;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Messages\Actions\CreateMessageAction;
use Kanvas\Social\Messages\DataTransferObject\MessageInput;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Users\Models\Users;
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

        $batchKey = $params['batchKey'] ?? null;
        $batch = null;
        if ($batchKey !== null && Cache::has($batchKey)) {
            $batch = Cache::get($batchKey);

            if (isset($batch['last_message_id']) && $batch['last_message_id'] !== $this->message->getId()) {
                return [
                    'message' => 'this is not the last message in the batch, skipping , we only respond to the last message',
                    'batch' => $batch,
                ];
            }

            Cache::forget($batchKey);
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
        $to = Str::replace('+', '', $to); // Strip any existing +

        // Add country code if needed (assuming +1 for 10-digit numbers)
        if (strlen($to) === 10) {
            $to = "+1{$to}";
        } else {
            $to = "+{$to}";
        }
        //if its missing a +1 add it
        $to = $this->hijackMessagePhone($to);

        $client = Client::getInstanceByCompany($this->message->company);
        $onChunk = function ($text, $data) use ($client, $to, $params): void {
            $this->createMessage($text, $to, $this->message, $this->channel);
            // Use the Twilio client to send a message
            $client->messages->create(
                $to, // to
                [
                    'from' => $params['from'],
                    'body' => $text,
                ]
            );
        };

        $messageConversation = $this->message->message['content'];
        if ($batchKey !== null && $batch !== null) {
            $messageConversation = '';
            foreach ($batch['messages'] as $batchMessage) {
                $messageConversation .= $batchMessage['body'] . "\n";
            }
        }

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

    private function createMessage(string $text, string $to, Message $message, Channel $channel): Message
    {
        $user = $message->user;
        $agentUser = $this->channel->app->get('kanvas_agent_user_id');
        if ($agentUser !== null) {
            $user = Users::getById((int) $agentUser);
        }

        $messageInput = new MessageInput(
            app: $message->app,
            company: $message->company,
            user: $user,
            type: $message->messageType,
            message: [
                    'content' => $text,
                    'raw_data' => $text,
                    'message_id' => '--',
                    'chat_jid' => $to,
                    'from_me' => true,
            ],
            is_public: 1,
            tags: [$to],
            //slug: Str::slug($text) . '-' . microtime()
        );

        $newMessage = new CreateMessageAction($messageInput)->execute();
        //$newMessage = $createMessageAction->execute();
        if ($message->entity() instanceof Model) {
            $newMessage->addEntity($message->entity());
        }
        $channel->addMessage($newMessage);

        return $newMessage;
    }
}
