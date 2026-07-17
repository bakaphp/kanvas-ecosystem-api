<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Actions;

use Illuminate\Database\Eloquent\Model;
use Kanvas\Exceptions\ModelNotFoundException;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Agents\Actions\Chat\AgentChatKernel;
use Kanvas\Intelligence\Agents\Helpers\AttachmentPromptBuilder;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Traits\DispatchesAttachmentDescriptionTrait;
use Kanvas\Intelligence\Notifications\AgentReplyNotification;
use Kanvas\Intelligence\Sessions\Actions\CreateSessionAction;
use Kanvas\Intelligence\Sessions\DataTransferObject\Session as SessionData;
use Kanvas\Intelligence\Sessions\Models\Session;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Messages\Actions\CreateMessageAction;
use Kanvas\Social\Messages\DataTransferObject\AiChatMessagePayload;
use Kanvas\Social\Messages\DataTransferObject\MessageInput;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\MessagesTypes\Actions\CreateMessageTypeAction;
use Kanvas\Social\MessagesTypes\DataTransferObject\MessageTypeInput;
use Kanvas\Users\Models\Users;
use Kanvas\Workflow\Enums\WorkflowEnum;

/**
 * Internal (non-connector) channel responder. Unlike RuntimeAgentChannelResponderAction (which
 * only resolves OpenClaw/Hermes deployments), this routes through AgentChatKernel so every backend
 * works — Neuron, Laravel, ADK, Runtime.
 */
class InternalAgentChannelResponderAction
{
    use DispatchesAttachmentDescriptionTrait;

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

        // Loop guard: our reply is flagged `from_me` and re-enters this workflow.
        if (($payload['from_me'] ?? false) === true) {
            return $this->message;
        }

        // Stable per-channel session keeps every inbound on one conversation (memory).
        $this->session ??= $this->resolveChannelSession();

        $this->dispatchAttachmentDescription($this->message, $this->agent, $this->channel);

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
            user: $this->message->user ?? $this->message->company->getAiAgentUserOrFail(),
            images: $imageUrls,
            currentLead: $entity instanceof Lead ? $entity : null,
            sourceChannel: $this->channel,
            sourceMessage: $this->message,
            documents: $documentUrls,
            persistConversation: false,
        )->execute();

        $replyMessage = $this->createReplyMessage($reply);
        $this->channel->addMessage($replyMessage, $this->agent->user);

        $this->notifyRecipientOfReply($replyMessage);

        return $replyMessage;
    }

    /**
     * Find-or-create the channel's durable session (uuid is channel-derived, so it's the one
     * conversation thread). Entity is the channel unless the inbound is tied to a Lead/People/Users
     * — those keep rich session content; anything else skips the generator (it only knows those three).
     */
    private function resolveChannelSession(): Session
    {
        $entity = $this->message->entity();
        $useEntity = $entity instanceof Lead || $entity instanceof People || $entity instanceof Users;
        $author = $this->message->user ?? $this->agent->user;

        return new CreateSessionAction(
            SessionData::from([
                'app' => $this->message->app,
                'company' => $this->message->company,
                'agent' => $this->agent,
                'channel' => $this->channel,
                'entity_namespace' => $useEntity ? $entity::class : $this->channel::class,
                'entity_id' => $useEntity ? (string) $entity->getId() : (string) $this->channel->getId(),
                'canal_id' => $this->message->getMessage()['chat_jid'] ?? (string) $this->channel->getId(),
                'user' => [
                    'id' => $author->getId(),
                    'name' => (string) ($author->displayname ?? ''),
                    'email' => (string) ($author->email ?? ''),
                ],
                'content' => $useEntity ? [] : ['channel' => $this->channel->uuid],
            ])
        )->execute();
    }

    /**
     * Push-notify the inbound author that the agent answered, mirroring the userChat path.
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

        // Fire CREATED manually below, after the entity link is attached, so rules see the entity.
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
