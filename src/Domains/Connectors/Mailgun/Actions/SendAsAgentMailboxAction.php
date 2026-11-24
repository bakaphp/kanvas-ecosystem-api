<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Mailgun\Actions;

use Closure;
use Kanvas\Communication\Channels\Email\EmailComposer;
use Kanvas\Communication\Messages\DataTransferObject\Content;
use Kanvas\Communication\Messages\DataTransferObject\Sender;
use Kanvas\Connectors\Mailgun\Client;
use Kanvas\Connectors\Mailgun\Services\AgentMailboxService;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\Agents\Models\Agent;
use Throwable;

/**
 * Ships one email from an agent's own address through Mailgun's API.
 *
 * The From is the whole point: only the account that owns the domain can authorize
 * `sofia@agents.acme.com`, and the company's SMTP relay is usually a different domain entirely —
 * which gets the mail rejected or spam-foldered. That is why an agent identity cannot be a header
 * swap on the notification/SMTP path; it has to be a different transport.
 *
 * Everything above this (which agent, which teammate, what to write) belongs to the caller. Reply-To is
 * always the mailbox so the answer comes back to the agent's own inbox and the agent handles it.
 */
class SendAsAgentMailboxAction
{
    public function __construct(
        private readonly Agent $agent,
        private readonly string $to,
        private readonly string $subject,
        private readonly string $markdownBody,
    ) {
    }

    public function execute(): string
    {
        $mailboxService = new AgentMailboxService();
        $address = $mailboxService->addressFor($this->agent);

        if ($address === null) {
            throw new ValidationException('Agent ' . (int) $this->agent->getId() . ' has no mailbox to send from.');
        }

        if (trim($this->markdownBody) === '') {
            throw new ValidationException('Cannot send an empty email');
        }

        if (trim($this->to) === '') {
            throw new ValidationException('Outbound email has no recipient');
        }

        try {
            $messageId = new Client($this->agent->app)->sendMessage(
                domain: $mailboxService->domainFor($this->agent),
                from: $this->agent->name . ' <' . $address . '>',
                to: $this->to,
                subject: $this->subject,
                text: EmailComposer::writtenText($this->markdownBody),
                html: EmailComposer::writtenHtml($this->markdownBody),
                // An answer that lands anywhere but the agent's own inbox never reaches the agent,
                // which is the entire point of giving it an address.
                headers: ['Reply-To' => $address],
            );
        } catch (ValidationException $exception) {
            // Mailgun answered and refused (Client::assertOk); a connection error is not a verdict on
            // the email and stays unrecorded, so the caller's retry is not shown as a failure.
            $this->fileDelivery(fn (FileEmailDeliveryAction $file) => $file->refused($exception->getMessage()));

            throw $exception;
        }

        if ($messageId !== '') {
            $this->fileDelivery(fn (FileEmailDeliveryAction $file) => $file->accepted($messageId));
        }

        return $messageId;
    }

    /**
     * Filing the delivery is bookkeeping: a failure here must not read as a failed send, or a caller
     * that retries on failure mails the person twice.
     */
    private function fileDelivery(Closure $file): void
    {
        try {
            $file(new FileEmailDeliveryAction(
                app: $this->agent->app,
                company: $this->agent->company,
                sender: Sender::agent($this->agent),
                to: $this->to,
                content: Content::text($this->markdownBody)->withSubject($this->subject),
            ));
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
