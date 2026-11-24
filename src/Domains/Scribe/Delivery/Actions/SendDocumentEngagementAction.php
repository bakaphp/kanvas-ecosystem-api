<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Delivery\Actions;

use Kanvas\Filesystem\Models\Filesystem;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Enums\LeadCommunicationChannelEnum;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Scribe\Delivery\DataTransferObject\DocumentDelivery;
use Kanvas\Scribe\Delivery\Enums\DocumentActionEnum;
use Kanvas\Scribe\Delivery\Services\DocumentFilesService;
use Kanvas\Scribe\Delivery\Services\DocumentLinkSenderService;
use Kanvas\Scribe\Invoices\Models\Invoice;
use Kanvas\Scribe\Quotes\Models\Quote;
use Kanvas\Users\Models\Users;

/**
 * One share of one document with one person (plan §5.2): the engagement (OpenDocumentEngagementAction,
 * reused when open), then its link sent by email or WhatsApp with the PDFs.
 */
class SendDocumentEngagementAction
{
    public function __construct(
        private readonly Invoice|Quote $document,
        private readonly People $people,
        private readonly Users $user,
        private readonly DocumentActionEnum $action,
        private readonly ?LeadCommunicationChannelEnum $channel = null,
        private readonly ?Agent $agent = null,
        private readonly ?string $body = null,
        private readonly ?string $subject = null,
        private readonly DocumentFilesService $files = new DocumentFilesService(),
        private readonly DocumentLinkSenderService $sender = new DocumentLinkSenderService(),
    ) {
    }

    public function assertDeliverable(): void
    {
        $this->opener()->assertSetUp();
        $this->sender->resolveTarget($this->people, $this->channel);
    }

    public function execute(): DocumentDelivery
    {
        $this->opener()->assertSetUp();
        $target = $this->sender->resolveTarget($this->people, $this->channel);
        $files = $this->files->forDelivery($this->document, $this->user);

        $opened = $this->opener($target->channel->value, $files)->execute();

        $message = $this->sender->send(
            target: $target,
            people: $this->people,
            user: $this->user,
            body: $this->composeBody($opened->link),
            subject: $this->subject ?? $this->defaultSubject(),
            files: $files,
            agent: $this->agent,
        );

        return new DocumentDelivery(
            engagement: $opened->engagement,
            link: $opened->link,
            target: $target,
            message: $message,
        );
    }

    /**
     * @param list<Filesystem> $files
     */
    private function opener(string $via = 'copy', array $files = []): OpenDocumentEngagementAction
    {
        return new OpenDocumentEngagementAction(
            document: $this->document,
            people: $this->people,
            user: $this->user,
            action: $this->action,
            via: $via,
            files: $files,
        );
    }

    /**
     * The link is appended when the caller's text does not already carry it: a reminder the agent
     * wrote must still lead to the document.
     */
    private function composeBody(string $link): string
    {
        $body = trim((string) $this->body);

        if ($body === '') {
            return $this->defaultBody($link);
        }

        return str_contains($body, $link) ? $body : $body . "\n\n" . $link;
    }

    private function defaultBody(string $link): string
    {
        $company = (string) $this->document->company->name;
        $amount = $this->document->currency . ' ' . number_format((float) $this->document->total_native, 2);
        $greeting = 'Hello ' . trim((string) $this->people->firstname) . ',';

        if ($this->document instanceof Invoice) {
            $due = $this->document->due_date !== null ? ', due ' . $this->document->due_date->toFormattedDateString() : '';

            return "{$greeting}\n\n{$company} sent you invoice {$this->document->documentNumber()} for {$amount}{$due}.\n\n"
                . "View and download it here: {$link}";
        }

        $verb = $this->action === DocumentActionEnum::SIGN_SOW ? 'Review and sign it here' : 'Review it here';

        return "{$greeting}\n\n{$company} sent you quote {$this->document->documentNumber()} for {$amount}.\n\n{$verb}: {$link}";
    }

    private function defaultSubject(): string
    {
        $noun = $this->document instanceof Invoice ? 'Invoice' : 'Quote';

        return "{$noun} {$this->document->documentNumber()} from {$this->document->company->name}";
    }
}
