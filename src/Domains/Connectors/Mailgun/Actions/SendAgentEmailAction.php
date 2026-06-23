<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Mailgun\Actions;

use Illuminate\Support\Facades\Notification;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Guild\Leads\Models\Lead;
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

        // Prefer the thread anchor (lead's first-touch subject), then any frozen inbound subject.
        $threadSubject = trim((string) (($entity?->get('title_email_follow_up'))
            ?: ($message->message['subject'] ?? '')));

        if ($threadSubject === '') {
            // No anchor and no stored subject anywhere → derive a fresh subject from the body.
            // This is a brand-new thread, so no "Re:" prefix; anchor it on the lead so later
            // follow-ups thread under it.
            $subject = new GenerateEmailSubjectAction($content)->execute();

            if ($entity instanceof Lead) {
                $entity->set('title_email_follow_up', $subject);
            }
        } else {
            // Existing thread → reply with a "Re:" prefix so Gmail keeps it in the same thread.
            $subject = preg_match('/^\s*re:/i', $threadSubject)
                ? $threadSubject
                : 'Re: ' . $threadSubject;
        }

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
