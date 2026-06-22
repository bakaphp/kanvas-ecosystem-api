<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Actions;

use Illuminate\Database\Eloquent\Model;
use Kanvas\Exceptions\ModelNotFoundException;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Agents\Actions\Chat\AgentChatKernel;
use Kanvas\Intelligence\Agents\Helpers\AttachmentPromptBuilder;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Notifications\AgentReplyNotification;
use Kanvas\Intelligence\Sessions\DataTransferObject\AiChatMessagePayload;
use Kanvas\Intelligence\Sessions\Models\Session;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Messages\Actions\CreateMessageAction;
use Kanvas\Social\Messages\DataTransferObject\MessageInput;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\MessagesTypes\Actions\CreateMessageTypeAction;
use Kanvas\Social\MessagesTypes\DataTransferObject\MessageTypeInput;
use Kanvas\Users\Models\Users;
use Kanvas\Workflow\Enums\WorkflowEnum;

/**
 * Internal (non-connector) channel responder. Mirrors the connector responders' shape
 * — react to a message that was just added to a channel, run the agent, persist the
 * reply back into the same channel — but routes through AgentChatKernel so it works
 * with every backend (Neuron, Laravel, ADK, Runtime), not just container runtimes.
 *
 * Why this exists separately from RuntimeAgentChannelResponderAction: that one calls
 * AgentRuntimeProviderFactory directly and only resolves OpenClaw/Hermes deployments.
 *
 * persistConversation is FALSE on the kernel call: the inbound message already exists
 * (the caller created it before firing AFTER_ADDING_MESSAGE_TO_CHANNEL), so the kernel
 * must NOT re-persist the user turn — only the reply is written, here.
 */
class InternalAgentChannelResponderAction
{
    private const string AGENT_RESPONSE_TYPE_VERB = 'ai-agent-response';

    public function __construct(
        protected Agent $agent,
        protected Message $message,
        protected Channel $channel,
        protected ?Session $session = null,
    ) {
    }

    public function execute(): Message
    {
        $payload = $this->message->getMessage();

        // Loop guard: the reply this action writes is flagged `from_me`, and that reply
        // re-enters the channel workflow. Bailing on `from_me` is what stops the agent
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

        $entity = $this->message->entity();

        $reply = new AgentChatKernel(
            agent: $this->agent,
            session: $this->session,
            message: $messageContent,
            user: $this->message->company->getAiAgentUserOrFail(),
            images: $imageUrls,
            currentLead: $entity instanceof Lead ? $entity : null,
            sourceChannel: $this->channel,
            sourceMessage: $this->message,
            persistConversation: false,
        )->execute();

        $replyMessage = $this->createReplyMessage($reply);
        $this->channel->addMessage($replyMessage, $this->agent->user);

        $this->notifyRecipientOfReply($replyMessage);

        return $replyMessage;
    }

    /**
     * Push-notify the author of the inbound message that the agent answered them.
     * Mirrors the userChat path (PersistChatTurnToSocialAction) so every agent reply
     * a human can see triggers the same notification, regardless of backend.
     */
    private function notifyRecipientOfReply(Message $replyMessage): void
    {
        $authorId = $this->message->users_id;

        if ($authorId <= 0) {
            return;
        }

        try {
            $recipient = Users::getById($authorId);
        } catch (ModelNotFoundException) {
            return;
        }

        $recipient->notify(
            new AgentReplyNotification(
                $replyMessage,
                $this->agent,
                $this->agent->user
            )
        );
    }

    /**
     * Split the inbound message's attachments by what the backends accept: image URLs go
     * through as native multimodal content, every other file (PDF, doc, ...) comes back
     * under `documents` so its link can be folded into the message text.
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
                'session_id' => $this->session?->uuid,
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
