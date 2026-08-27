<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Mailgun\Actions;

use Kanvas\Exceptions\ValidationException;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Agents\Actions\BaseAgentChannelReplyAction;
use Kanvas\Intelligence\Agents\Actions\Chat\AgentChatKernel;
use Kanvas\Intelligence\Agents\Exceptions\AgentReplySkippedException;
use Kanvas\Intelligence\Agents\Helpers\ChatHelper;
use Override;

class AgentChannelResponderAction extends BaseAgentChannelReplyAction
{
    protected string $messageTypeVerb = 'mailgun-email';
    protected string $communicationChannel = 'email';
    protected bool $supportsHumanApproval = true;

    #[Override]
    public function execute(array $params = []): array
    {
        $messageConversation = $this->message->message['content'] ?? null;

        if ($messageConversation === null) {
            throw new ValidationException('No conversation found');
        }

        $entity = $this->message->entity();
        if ($entity === null) {
            throw new ValidationException('No entity found');
        }

        // Cold-inbound leads have no outreach anchor (AgentReachOutOnChannelAction never ran).
        // Persist the incoming subject as the thread anchor — first touch wins — so later
        // follow-ups thread under it instead of falling back to the company name (new thread).
        if ($entity instanceof Lead) {
            $inboundSubject = trim((string) ($this->message->message['subject'] ?? ''));
            if ($inboundSubject !== '' && ! $entity->get('title_email_follow_up')) {
                $entity->set(
                    'title_email_follow_up',
                    (string) preg_replace('/^\s*re:\s*/i', '', $inboundSubject)
                );
            }
        }

        // A rule that fans every inbound message at this activity hands us SMS/WhatsApp payloads
        // too — those carry a phone in chat_jid and no sender address, so there is no recipient to
        // email. Skip silently instead of guessing one.
        $fromEmail = trim((string) ($this->message->message['from_email'] ?? ''));

        if ($fromEmail === '') {
            throw new AgentReplySkippedException('Inbound message has no from_email, not an email message');
        }

        $channelId = $this->hijackMessagePhone($fromEmail);

        // Bytes stay out of the model (token cost); the filesystem_id marker still goes in, or the model has nothing to ground a reply in and reuses an older attachment from its own chat history.
        $messageConversation .= $this->currentAttachmentMarkers();

        $responseContent = new AgentChatKernel(
            agent: $this->agent,
            session: $this->session,
            message: $messageConversation,
            user: $this->message->company->getAiAgentUserOrFail(),
            currentLead: $entity instanceof Lead ? $entity : null,
            sourceChannel: $this->channel,
            sourceMessage: $this->message,
            persistConversation: false,
        )->execute();

        $responseText = ChatHelper::extractTextFromResponse($responseContent);

        $messageResponse = $this->createMessage(
            $responseText,
            $channelId,
            $this->message,
            $this->channel,
            rawResponse: $responseContent
        );

        // Freeze the inbound subject on the outbound so SendAgentEmailAction can thread the reply
        // (title_email_follow_up first, this as fallback) whether it ships now or after approval.
        // The inbound Message-Id rides along for the same reason: a mailbox send turns it into
        // In-Reply-To/References, and by approval time the inbound message is no longer in hand.
        $messageResponse->addMessage([
            'subject' => $this->message->message['subject'] ?? null,
            'email_message_id' => $this->message->message['email_message_id'] ?? null,
            'email_references' => $this->message->message['email_references'] ?? null,
            'response_text' => $responseText,
        ]);

        if (! $messageResponse->is_locked) {
            new SendAgentEmailAction($messageResponse)->execute();
        }

        return [
            'message' => $messageConversation,
            'responseText' => $responseContent,
            'response' => $responseText,
        ];
    }

    // One line per file attached to THIS message, so the model can call extract_invoice_data(filesystem_id) itself; empty when nothing was attached.
    private function currentAttachmentMarkers(): string
    {
        $files = $this->message->files;

        if ($files->isEmpty()) {
            return '';
        }

        $lines = $files->map(
            fn ($file) => "[Attached file on this message — filesystem_id: {$file->id}, filename: \"{$file->name}\"]"
        );

        return "\n\n" . $lines->implode("\n");
    }
}
