<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Actions;

use Kanvas\Exceptions\ValidationException;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Agents\Actions\Chat\AgentChatKernel;
use Kanvas\Intelligence\Agents\Helpers\AttachmentPromptBuilder;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Traits\DispatchesAttachmentDescriptionTrait;
use Kanvas\Intelligence\Sessions\Actions\CreateSessionAction;
use Kanvas\Intelligence\Sessions\DataTransferObject\Session as SessionData;
use Kanvas\Intelligence\Sessions\Models\Session;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Users\Models\Users;
use Override;

/**
 * Internal (non-connector) channel responder. Unlike RuntimeAgentChannelResponderAction (which
 * only resolves OpenClaw/Hermes deployments), this routes through AgentChatKernel so every backend
 * works — Neuron, Laravel, ADK, Runtime.
 */
class InternalAgentChannelResponderAction extends AbstractAgentChannelResponderAction
{
    use DispatchesAttachmentDescriptionTrait;

    public function __construct(
        protected Agent $agent,
        protected Message $message,
        protected Channel $channel,
        protected ?Session $session = null,
    ) {
    }

    #[Override]
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

        ['images' => $imageUrls, 'documents' => $documentUrls] = $this->message->attachmentUrls();

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

        $this->notifyRecipientOfReply($replyMessage);

        return $replyMessage;
    }

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

    #[Override]
    protected function extraMessagePayload(): array
    {
        return ['session_id' => $this->session?->uuid];
    }
}
