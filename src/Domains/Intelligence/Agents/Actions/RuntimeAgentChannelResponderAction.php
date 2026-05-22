<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Actions;

use Illuminate\Database\Eloquent\Model;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Filesystem\Enums\MediaTypeEnum;
use Kanvas\Intelligence\AgentRuntime\Contracts\AgentRuntimeProvider;
use Kanvas\Intelligence\AgentRuntime\Providers\AgentRuntimeProviderFactory;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentDeployment;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Messages\Actions\CreateMessageAction;
use Kanvas\Social\Messages\DataTransferObject\MessageInput;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\MessagesTypes\Actions\CreateMessageTypeAction;
use Kanvas\Social\MessagesTypes\DataTransferObject\MessageTypeInput;
use Kanvas\Workflow\Enums\WorkflowEnum;

class RuntimeAgentChannelResponderAction
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

        // Loop guard: every reply this action writes is flagged `from_me`, and that reply
        // re-enters the channel workflow. Bailing on `from_me` here is what stops the agent
        // from answering its own messages forever.
        if (($payload['from_me'] ?? false) === true) {
            return $this->message;
        }

        $content = (string) ($payload['content'] ?? '');

        if ($content === '') {
            throw new ValidationException('Message has no content to send to the agent');
        }

        $sessionKey = 'kanvas-channel-' . (string) $this->channel->getId();

        $reply = $this->resolveProvider()->chat(
            agent: $this->agent,
            message: $content,
            sessionKey: $sessionKey,
            images: $this->extractImageUrls(),
        );

        $replyMessage = $this->createReplyMessage($reply);
        $this->channel->addMessage($replyMessage, $this->agent->user);

        return $replyMessage;
    }

    protected function resolveProvider(): AgentRuntimeProvider
    {
        $deployment = $this->agent->activeDeployment;

        return $deployment instanceof AgentDeployment
            ? AgentRuntimeProviderFactory::forDeployment($deployment)
            : AgentRuntimeProviderFactory::forAgent($this->agent);
    }

    /**
     * @return list<string>
     */
    private function extractImageUrls(): array
    {
        $urls = [];

        foreach ($this->message->files as $file) {
            $url = (string) ($file->url ?? '');
            if ($url === '') {
                continue;
            }

            if (! MediaTypeEnum::fromExtension((string) ($file->file_type ?? ''))->isImage()) {
                continue;
            }

            $urls[] = $url;
        }

        return $urls;
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

        // Suppress the create action's own workflow pass; CREATED is fired manually below,
        // after the source-entity link is attached, so rules that read the entity see it.
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
