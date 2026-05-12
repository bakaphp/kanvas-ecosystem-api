<?php

declare(strict_types=1);

namespace Kanvas\Connectors\AgentRuntime\Actions;

use Illuminate\Database\Eloquent\Model;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Types\OpenClawAgentHandler;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Messages\Actions\CreateMessageAction;
use Kanvas\Social\Messages\DataTransferObject\MessageInput;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\MessagesTypes\Actions\CreateMessageTypeAction;
use Kanvas\Social\MessagesTypes\DataTransferObject\MessageTypeInput;
use Kanvas\Workflow\Enums\WorkflowEnum;

class SendChannelMessageToAgentAction
{
    private const string AGENT_RESPONSE_TYPE_VERB = 'ai-agent-response';

    public function __construct(
        protected Agent $agent,
        protected Message $message,
        protected Channel $channel,
    ) {
    }

    public function execute(): Message
    {
        $payload = $this->message->getMessage();

        if (($payload['from_me'] ?? false) === true) {
            return $this->message;
        }

        $content = (string) ($payload['content'] ?? '');

        if ($content === '') {
            throw new ValidationException('Message has no content to send to the agent');
        }

        $sessionKey = 'kanvas-channel-' . (string) $this->channel->getId();

        $handler = new OpenClawAgentHandler();
        $handler->setAgent($this->agent);

        $reply = $handler->chat($content, $sessionKey);

        $replyMessage = $this->createReplyMessage($reply);
        $this->channel->addMessage($replyMessage, $this->agent->user);

        return $replyMessage;
    }

    private function createReplyMessage(string $reply): Message
    {
        $app = $this->message->app;

        $messageType = new CreateMessageTypeAction(
            new MessageTypeInput(
                $app->getId(),
                0,
                self::AGENT_RESPONSE_TYPE_VERB,
                self::AGENT_RESPONSE_TYPE_VERB,
            )
        )->execute();

        $originalPayload = $this->message->getMessage();

        $messageInput = new MessageInput(
            app: $app,
            company: $this->message->company,
            user: $this->agent->user,
            type: $messageType,
            message: [
                'content' => $reply,
                'raw_data' => $reply,
                'message_id' => '--',
                'chat_jid' => $originalPayload['chat_jid'] ?? null,
                'from_me' => true,
                'from_ia' => true,
            ],
            is_public: 1,
            tags: [self::AGENT_RESPONSE_TYPE_VERB],
        );

        $createMessage = new CreateMessageAction($messageInput);
        $createMessage->runWorkflow = false;

        $replyMessage = $createMessage->execute();

        $entity = $this->message->entity();
        if ($entity instanceof Model) {
            $replyMessage->addEntity($entity);
        }

        $replyMessage->fireWorkflow(
            WorkflowEnum::CREATED->value,
            true,
            ['app' => $app],
        );

        return $replyMessage;
    }
}
