<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Mailgun\Actions;

use Illuminate\Support\Facades\Notification;
use Kanvas\Connectors\Mailgun\Services\AgentMailboxService;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Leads\Repositories\LeadsRepository;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Notifications\Support\MarkdownEmailRenderer;
use Kanvas\Notifications\Templates\Blank;
use Kanvas\Social\Messages\Models\Message;
use Throwable;

/**
 * Sends an agent's outbound email reply from an already-persisted Message.
 *
 * Sourcing every input from the stored message (recipient, subject, body) is what lets the
 * same send run both inline (live auto-reply) and later (human approval of a locked draft).
 *
 * Two exits: an agent with its own mailbox sends from its own address through Mailgun's API; every
 * other agent goes out on the company's SMTP identity, as it always has.
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
        $subject = $this->resolveSubject($entity, $content);
        $agent = $this->resolveAgentMailboxOwner();

        if ($agent !== null) {
            new SendMailboxEmailAction($agent, $message, $subject)->execute();

            return;
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

    private function resolveSubject(mixed $entity, string $content): string
    {
        $subject = null;

        if ($entity instanceof Lead) {
            $subject = $entity->get('title_email_follow_up');
        } elseif ($entity instanceof People) {
            $subject = LeadsRepository::getPeopleActiveLead($entity)?->get('title_email_follow_up');
        }

        // Prefer the thread anchor (lead's first-touch subject), then any frozen inbound subject.
        $threadSubject = trim((string) $subject ?: (string) ($this->outboundMessage->message['subject'] ?? ''));

        if ($threadSubject === '') {
            // No anchor and no stored subject anywhere → derive a fresh subject from the body.
            // This is a brand-new thread, so no "Re:" prefix; anchor it on the lead so later
            // follow-ups thread under it.
            $subject = new GenerateEmailSubjectAction($content)->execute();

            if ($entity instanceof Lead) {
                $entity->set('title_email_follow_up', $subject);
            }

            return $subject;
        }

        // Existing thread → reply with a "Re:" prefix so Gmail keeps it in the same thread.
        return preg_match('/^\s*re:/i', $threadSubject) ? $threadSubject : 'Re: ' . $threadSubject;
    }

    /**
     * The agent that wrote this reply, when it has a mailbox of its own. A missing or deleted agent
     * is not an error here — it just means the send falls back to the company identity.
     */
    private function resolveAgentMailboxOwner(): ?Agent
    {
        $agentId = (int) ($this->outboundMessage->message['agent_id'] ?? 0);

        if ($agentId === 0) {
            return null;
        }

        try {
            /** @var Agent $agent */
            $agent = Agent::getById($agentId, $this->outboundMessage->app);
        } catch (Throwable) {
            return null;
        }

        return new AgentMailboxService()->hasMailbox($agent) ? $agent : null;
    }
}
