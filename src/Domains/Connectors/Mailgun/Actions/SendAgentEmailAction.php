<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Mailgun\Actions;

use Illuminate\Support\Facades\Notification;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Notifications\Support\MarkdownEmailRenderer;
use Kanvas\Notifications\Templates\Blank;
use Kanvas\Social\Messages\Models\Message;

/**
 * Sends an agent's outbound email reply from an already-persisted Message.
 *
 * Sourcing every input from the stored message (recipient, subject, body) is what lets the
 * same send run both inline (live auto-reply) and later (human approval of a locked draft).
 */
class SendAgentEmailAction
{
    public function __construct(
        protected Message $outboundMessage
    ) {
    }

    public function execute(): void
    {
        $message = $this->outboundMessage;
        $content = (string) ($message->message['content'] ?? '');

        if ($content === '') {
            throw new ValidationException('Cannot send an empty email');
        }

        $recipient = (string) ($message->message['chat_jid'] ?? '');
        if ($recipient === '') {
            throw new ValidationException('Outbound email has no recipient');
        }

        $entity = $message->entity();

        // Reply with a "Re:" prefix so Gmail threads under the existing conversation, anchored on
        // the lead's title_email_follow_up (first-touch subject) exactly like the live path.
        $threadSubject = (string) (($entity?->get('title_email_follow_up'))
            ?: ($message->message['subject'] ?? 'No subject'));

        $subject = preg_match('/^\s*re:/i', $threadSubject)
            ? $threadSubject
            : 'Re: ' . $threadSubject;

        $notification = new Blank(
            'agent-email-response',
            [
                'content' => MarkdownEmailRenderer::toEmailHtml($content),
                'lead' => $entity,
                'company' => $message->company,
                'signature' => true,
            ],
            ['mail'],
            $message
        );

        $notification->setFromUser($message->user);
        $notification->setSubject($subject);

        Notification::route('mail', $recipient)->notify($notification);
    }
}
