<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Actions;

use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\AgentRuntime\Contracts\AgentRuntimeProvider;
use Kanvas\Intelligence\AgentRuntime\Providers\AgentRuntimeProviderFactory;
use Kanvas\Intelligence\Agents\Helpers\AttachmentPromptBuilder;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Messages\Models\Message;
use Override;

class RuntimeAgentChannelResponderAction extends AbstractAgentChannelResponderAction
{
    public function __construct(
        protected Agent $agent,
        protected Message $message,
        protected Channel $channel,
    ) {
    }

    #[Override]
    public function execute(): Message
    {
        $payload = $this->message->getMessage();

        // Loop guard: every reply this action writes is flagged `from_me`, and that reply
        // re-enters the channel workflow. Bailing on `from_me` here is what stops the agent
        // from answering its own messages forever.
        if (($payload['from_me'] ?? false) === true) {
            return $this->message;
        }

        ['images' => $imageUrls, 'documents' => $documentUrls] = $this->message->attachmentUrls();

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

        $this->notifyRecipientOfReply($replyMessage);

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
}
