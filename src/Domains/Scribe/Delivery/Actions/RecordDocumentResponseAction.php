<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Delivery\Actions;

use Baka\Support\Str;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Kanvas\ActionEngine\Engagements\Models\Engagement;
use Kanvas\ActionEngine\Enums\ActionStatusEnum;
use Kanvas\Filesystem\Services\FilesystemServices;
use Kanvas\Scribe\Delivery\Enums\DocumentActionEnum;
use Kanvas\Scribe\Delivery\Enums\DocumentSignalEnum;
use Kanvas\Scribe\Delivery\Exceptions\DocumentDeliveryException;
use Kanvas\Scribe\Delivery\Models\DocumentAcceptance;
use Kanvas\Scribe\Delivery\Services\DocumentEngagementSignalService;
use Kanvas\Scribe\Delivery\Support\QuoteSignatureRule;
use Kanvas\Scribe\Invoices\Models\Invoice;
use Kanvas\Scribe\Quotes\Actions\AcceptQuoteAction;
use Kanvas\Scribe\Quotes\Actions\RejectQuoteAction;
use Kanvas\Scribe\Quotes\Enums\QuoteStatusEnum;
use Kanvas\Scribe\Quotes\Models\Quote;
use Kanvas\Scribe\Quotes\Services\QuoteStateMachineService;
use Kanvas\Users\Models\Users;

/**
 * What the customer does on the public document page: opened, downloaded, and for a quote accepted
 * (signed when it carries a SOW) or declined with a reason. The Scribe side runs first — an accept the
 * quote's state machine refuses never shows as `submitted` on the engagement.
 *
 * Accept goes through AcceptQuoteAction and nothing else, so the SOW's billing schedules start the
 * same way the agent's `answer_quote` starts them.
 */
class RecordDocumentResponseAction
{
    public const string SIGNATURE_FIELD = 'quote_signature';
    private const int MAX_SIGNATURE_BYTES = 1_048_576;

    public function __construct(
        private readonly Engagement $engagement,
        private readonly ActionStatusEnum $status,
        private readonly ?string $signerName = null,
        private readonly ?string $signature = null,
        private readonly ?string $reason = null,
        private readonly ?string $ipAddress = null,
        private readonly ?string $userAgent = null,
        private readonly DocumentEngagementSignalService $signals = new DocumentEngagementSignalService(),
    ) {
    }

    public function execute(): Engagement
    {
        $document = RecordDocumentEngagementStatusAction::documentOf($this->engagement);
        $action = DocumentActionEnum::from((string) $this->engagement->slug);

        $data = match ($this->status) {
            ActionStatusEnum::OPEN, ActionStatusEnum::DOWNLOADED => [],
            ActionStatusEnum::SUBMITTED => $this->accept($this->quoteFor($document, $action)),
            ActionStatusEnum::DECLINED => $this->decline($this->quoteFor($document, $action)),
            default => throw $this->refuse("The page cannot set an engagement to {$this->status->value}."),
        };

        return new RecordDocumentEngagementStatusAction($this->engagement, $this->status, $data)->execute();
    }

    private function quoteFor(Invoice|Quote $document, DocumentActionEnum $action): Quote
    {
        if (! $document instanceof Quote || ! $action->isQuoteAction()) {
            throw $this->refuse('Only a quote can be accepted or declined.');
        }

        return $document;
    }

    /**
     * The transition is checked before anything is stored, and the acceptance row is written in the
     * transaction that accepts the quote: a refused accept leaves no signature and no evidence, and a
     * repeat accept returns the first signer instead of replacing them.
     *
     * @return array<string, mixed>
     */
    private function accept(Quote $quote): array
    {
        $signer = Str::trimToNull($this->signerName);

        if ($signer === null && QuoteSignatureRule::requiresSignature($quote)) {
            throw $this->refuse('This quote has a Statement of Work: it needs the signer\'s name to be accepted.');
        }

        $signature = $this->decodedSignature();
        new QuoteStateMachineService()->assertTransition($quote, QuoteStatusEnum::ACCEPTED);

        $existing = DocumentAcceptance::forQuoteRevision($quote);
        if ($existing !== null) {
            new AcceptQuoteAction(quote: $quote, user: $this->sharer())->execute();

            return $this->acceptanceData($existing);
        }

        try {
            $acceptance = $this->recordAcceptance($quote, $signer, $signature);
        } catch (UniqueConstraintViolationException) {
            // A concurrent accept of the same revision won; its signer stands.
            $acceptance = DocumentAcceptance::forQuoteRevision($quote) ?? throw $this->refuse('The quote could not be accepted.');
        }

        return $this->acceptanceData($acceptance);
    }

    /**
     * @param array{bytes: string, extension: string}|null $signature
     */
    private function recordAcceptance(Quote $quote, ?string $signer, ?array $signature): DocumentAcceptance
    {
        return DB::connection('accounting')->transaction(function () use ($quote, $signer, $signature): DocumentAcceptance {
            new AcceptQuoteAction(quote: $quote, user: $this->sharer())->execute();

            return DocumentAcceptance::create([
                'apps_id' => $quote->apps_id,
                'companies_id' => $quote->companies_id,
                'quote_id' => $quote->getId(),
                'quote_revision' => (int) $quote->revision_number,
                'sow_message_id' => $quote->sow_message_id,
                'sow_hash' => $this->sowHash($quote),
                'engagement_id' => $this->engagement->getId(),
                'people_id' => $this->engagement->people_id,
                'signer_name' => $signer,
                'signer_email' => $this->signerEmail($quote),
                'signature_filesystem_id' => $signature !== null ? $this->storeSignature($quote, $signature) : null,
                'accepted_at' => Carbon::now(),
                'ip_address' => Str::trimToNull($this->ipAddress),
                'user_agent' => $this->userAgent !== null ? Str::trimToNull(mb_substr($this->userAgent, 0, 512)) : null,
            ]);
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function acceptanceData(DocumentAcceptance $acceptance): array
    {
        return [
            'acceptance_id' => $acceptance->getId(),
            'signer_name' => $acceptance->signer_name,
            'signed_at' => $acceptance->accepted_at->toIso8601String(),
            'people_id' => $acceptance->people_id,
            'signature_file_id' => $acceptance->signature_filesystem_id,
        ];
    }

    private function sowHash(Quote $quote): ?string
    {
        $raw = $quote->sowMessage?->getRawOriginal('message');

        return $raw !== null ? hash('sha256', (string) $raw) : null;
    }

    private function signerEmail(Quote $quote): ?string
    {
        return Str::trimToNull($this->engagement->people?->getEmails()->first()?->value)
            ?? Str::trimToNull($quote->billable_email);
    }

    /**
     * @return array<string, mixed>
     */
    private function decline(Quote $quote): array
    {
        $reason = Str::trimToNull($this->reason);

        if ($reason === null) {
            throw $this->refuse('Tell us why you are declining so we can revise the quote.');
        }

        new RejectQuoteAction(
            quote: $quote,
            lostReason: mb_substr($reason, 0, 191),
            user: $this->sharer(),
        )->execute();

        $this->signals->raise(
            $quote,
            DocumentSignalEnum::QUOTE_DECLINED,
            "why {$quote->billable_display_name} declined quote {$quote->quote_number} (they wrote: \"{$reason}\"); "
            . 'reply in their thread, then offer a new quote with create_quote if it can be saved; '
            . 'a rejected quote cannot be revised'
        );

        return ['reason' => $reason];
    }

    /**
     * A data URI only: the page draws the signature and posts it inline, so nothing here ever fetches a
     * URL a customer supplied.
     *
     * @return array{bytes: string, extension: string}|null
     */
    private function decodedSignature(): ?array
    {
        $signature = Str::trimToNull($this->signature);
        if ($signature === null) {
            return null;
        }

        if (preg_match('#^data:image/(png|jpeg);base64,(.+)$#', $signature, $matches) !== 1) {
            throw $this->refuse('The signature must be a PNG or JPEG data URI.');
        }

        $bytes = base64_decode($matches[2], true);
        if ($bytes === false || strlen($bytes) > self::MAX_SIGNATURE_BYTES) {
            throw $this->refuse('The signature image is unreadable or too large.');
        }

        return ['bytes' => $bytes, 'extension' => $matches[1] === 'jpeg' ? 'jpg' : 'png'];
    }

    /**
     * @param array{bytes: string, extension: string} $signature
     */
    private function storeSignature(Quote $quote, array $signature): int
    {
        $file = new FilesystemServices($quote->app, $quote->company)->uploadContents(
            $signature['bytes'],
            'signature-' . $quote->uuid . '.' . $signature['extension'],
            $this->sharer()
        );
        $quote->addFile($file, self::SIGNATURE_FIELD);

        return $file->getId();
    }

    private function sharer(): Users
    {
        /** @var Users $user */
        $user = $this->engagement->user;

        return $user;
    }

    private function refuse(string $message): DocumentDeliveryException
    {
        return new DocumentDeliveryException(DocumentDeliveryException::INVALID_STATUS, $message);
    }
}
