<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Mailgun\Actions;

use Illuminate\Support\Facades\Notification;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Agents\Actions\BaseAgentChannelReplyAction;
use Kanvas\Intelligence\Agents\Actions\Chat\AgentChatKernel;
use Kanvas\Intelligence\Agents\Helpers\ChatHelper;
use Kanvas\Notifications\Support\MarkdownEmailRenderer;
use Kanvas\Notifications\Templates\Blank;
use Kanvas\Social\Messages\Models\Message;
use Override;

class AgentChannelResponderAction extends BaseAgentChannelReplyAction
{
    protected string $messageTypeVerb = 'mailgun-email';
    protected string $communicationChannel = 'email';

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

        $channelId = $this->hijackMessagePhone($this->message->message['from_email']);

        $emailRequest = [
            'template_name' => 'agent-email-response',
            'email' => $channelId,
            'subject' => $entity->get('title_email_follow_up')
                ?? 'Re: ' . ($this->message->message['subject'] ?? 'No subject'),
        ];

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

        $emailData = [
            'content' => $responseText,
            'lead' => $entity,
            'company' => $this->message->company,
        ];

        $messageResponse = $this->createMessage(
            $responseText,
            $channelId,
            $this->message,
            $this->channel
        );

        if (! $messageResponse->is_locked) {
            $this->sendEmail(
                $emailRequest,
                $emailData,
                $this->message
            );
        }

        return [
            'message' => $messageConversation,
            'responseText' => $responseContent,
            'response' => $responseText,
        ];
    }

    protected function hijackMessagePhone(string $channelId): string
    {
        if ($this->agent->company->get('allow_session_hijack', false)
          && $this->agent->company->get('overwrite_phone_number') !== null
        ) {
            $overwriteConfig = $this->agent->company->get('overwrite_phone_number');
            $originalRemoteJid = $channelId;

            // Reverse lookup: hijacked -> original
            $reverseMapping = array_flip($overwriteConfig);
            if (isset($reverseMapping[$originalRemoteJid])) {
                return $reverseMapping[$originalRemoteJid];
            }
        }

        return $channelId;
    }

    protected function sendEmail(array $request, array $data, Message $message): void
    {
        $data['signature'] = true;
        // Agent replies are Markdown; the mail layout renders raw HTML, so convert here.
        $data['content'] = MarkdownEmailRenderer::toEmailHtml((string) ($data['content'] ?? ''));
        $notification = new Blank(
            $request['template_name'],
            $data,
            ['mail'],
            $message
        );

        $notification->setFromUser($message->user);
        $notification->setSubject($request['subject']);
        Notification::route('mail', $request['email'])->notify($notification);
    }
}
