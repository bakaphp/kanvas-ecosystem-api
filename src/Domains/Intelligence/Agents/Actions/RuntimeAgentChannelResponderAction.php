<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Actions;

use Illuminate\Database\Eloquent\Model;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\AgentRuntime\Contracts\AgentRuntimeProvider;
use Kanvas\Intelligence\AgentRuntime\Providers\AgentRuntimeProviderFactory;
use Kanvas\Intelligence\Agents\Helpers\AttachmentPromptBuilder;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Sessions\DataTransferObject\AiChatMessagePayload;
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

        ['images' => $imageUrls, 'documents' => $documentUrls] = $this->collectAttachmentUrls();

        $messageContent = AttachmentPromptBuilder::withAttachments(
            (string) ($payload['content'] ?? ''),
            $documentUrls,
        );

        if ($messageContent === '' && $imageUrls === []) {
            throw new ValidationException('Message has no content or attachments to send to the agent');
        }

        $reply = $this->resolveProvider()->chat(
            agent: $this->agent,
            message: $messageContent,
            sessionKey: $this->channel->uuid,
            images: $imageUrls,
        );

        $replyMessage = $this->createReplyMessage($reply);
        $this->channel->addMessage($replyMessage, $this->agent->user);

        return $replyMessage;
    }

    /**
     * Resolve the agent's runtime through the shared factory — the same resolution
     * RunRuntimeChatAction uses, so every chat path reaches the same provider.
     * Overridable in tests to inject a fake provider without touching the network.
     */
    protected function resolveProvider(): AgentRuntimeProvider
    {
        return AgentRuntimeProviderFactory::forRunningAgent($this->agent);
    }

    /**
     * Split the inbound message's attachments by what the runtime can actually accept:
     * image URLs go through as native multimodal content, every other file (PDF, doc, ...)
     * comes back under `documents` so its link can be folded into the message text — the
     * runtime chat APIs reject non-image content uploads with `400 unsupported_content_type`.
     *
     * @return array{images: list<string>, documents: list<string>}
     */
    private function collectAttachmentUrls(): array
    {
        $images = [];
        $documents = [];

        foreach ($this->message->files as $file) {
            $url = $file->url;
            if ($url === '') {
                continue;
            }

            if ($file->mediaType()->isImage()) {
                $images[] = $url;
            } else {
                $documents[] = $url;
            }
        }

        return ['images' => $images, 'documents' => $documents];
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
            message: AiChatMessagePayload::from([
                'content' => $reply,
                'from_me' => true,
                'from_ia' => true,
                'agent_id' => (int) $this->agent->getId(),
                'raw_data' => $reply,
                'message_id' => '--',
                'chat_jid' => $originalPayload['chat_jid'] ?? null,
            ])->toArray(),
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
