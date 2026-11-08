<?php

declare(strict_types=1);

namespace Kanvas\Scribe\ElectronicInvoicing\Actions;

use Baka\Support\DateHelper;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Filesystem\Services\FilesystemServices;
use Kanvas\NervousSystem\Ledger\Enums\EventStatusEnum;
use Kanvas\Scribe\Bills\Models\Bill;
use Kanvas\Scribe\ElectronicInvoicing\Contracts\FiscalRegimeInterface;
use Kanvas\Scribe\ElectronicInvoicing\DataTransferObject\EcfSubmission;
use Kanvas\Scribe\ElectronicInvoicing\Enums\EcfPdfSourceEnum;
use Kanvas\Scribe\ElectronicInvoicing\Enums\FiscalDocumentStatusEnum;
use Kanvas\Scribe\ElectronicInvoicing\Enums\FiscalEventTypeEnum;
use Kanvas\Scribe\ElectronicInvoicing\Enums\FiscalSubmissionStatusEnum;
use Kanvas\Scribe\ElectronicInvoicing\Jobs\GenerateEcfPdfJob;
use Kanvas\Scribe\ElectronicInvoicing\Jobs\PollEcfResultJob;
use Kanvas\Scribe\ElectronicInvoicing\Models\FiscalDocument;
use Kanvas\Scribe\ElectronicInvoicing\Models\FiscalDocumentSubmission;
use Kanvas\Scribe\ElectronicInvoicing\Support\EcfCompliance;
use Kanvas\Scribe\Expenses\Models\Expense;
use Kanvas\Scribe\Invoices\Actions\VoidInvoiceAction;
use Kanvas\Scribe\Invoices\Enums\InvoiceDocumentStatusEnum;
use Kanvas\Scribe\Invoices\Models\Invoice;
use Kanvas\Users\Models\Users;
use Throwable;

/**
 * Applies what the authority answered about one fiscal row, whichever way it arrived (the send, the poll, a
 * webhook). Event first, deduped, then a guarded transition:
 *  - a terminal status is never overwritten by a non-terminal one, and a repeat is a no-op;
 *  - an answer older than the last one applied is recorded but not applied;
 *  - accepted vs rejected for the same row is recorded and flags the row for review instead of flipping it.
 *
 * Only the entity's current row has side effects (PDF, void, ledger events, polling). An answer about a
 * superseded row stays in that row's history.
 *
 * Rejections (plan §5): DGII's secuenciaUtilizada false leaves the number reusable — the invoice stays as is
 * and the same row is resubmitted after the fix. True or absent burns it: an invoice is voided so its JE nets
 * to zero (DGII: never on the books), a bill or expense is flagged to issue on a new row.
 */
class ApplyFiscalAnswerAction
{
    public const string PDF_FIELD_NAME = 'ecf_pdf';
    public const string VOID_REASON = 'ecf_rejected';

    private const string EFFECT_ACCEPTED = 'accepted';
    private const string EFFECT_REJECTED = 'rejected';
    private const string EFFECT_CANCELLED = 'cancelled';
    private const string EFFECT_CONFLICT = 'conflict';

    public bool $applied = false;

    public function __construct(
        public readonly FiscalDocument $fiscalDocument,
        public readonly EcfSubmission $answer,
        public readonly ?FiscalDocumentSubmission $submission = null,
        public readonly ?Carbon $answeredAt = null,
        public readonly int $pollAttempt = 0,
    ) {
    }

    /**
     * The event, the submission update and the transition commit together under the fiscal row's lock. The
     * side effects (void, PDF, ledger, poll) run after that commit and are marked done on the event, so a
     * retry after a crash at any point finishes the work instead of treating the answer as a duplicate.
     */
    public function execute(): FiscalDocument
    {
        $answeredAt = $this->answeredAt ?? Carbon::now();
        $target = FiscalDocumentStatusEnum::fromAnswer($this->answer->status);

        [$fiscal, $event] = DB::connection('accounting')->transaction(function () use ($answeredAt, $target): array {
            /** @var FiscalDocument $fiscal */
            $fiscal = FiscalDocument::query()->whereKey($this->fiscalDocument->getKey())->lockForUpdate()->firstOrFail();

            if ($this->answer->encf !== '' && $this->answer->encf !== $fiscal->number) {
                throw new ValidationException(
                    "The provider answered about {$this->answer->encf}, but fiscal document {$fiscal->uuid} is {$fiscal->number}."
                );
            }

            $event = new RecordFiscalEventAction(
                document: $fiscal,
                type: FiscalEventTypeEnum::AUTHORITY_ANSWER,
                direction: 'received',
                status: $target->value,
                dedupeKey: $this->dedupeKey(),
                payload: $this->answerPayload(),
                attributes: ['resolved_at' => $answeredAt],
            )->execute();

            if ($event->applied_at === null) {
                $this->recordOnSubmission($fiscal);
                $event->effect = $this->transition($fiscal, $target, $answeredAt);
                $event->applied_at = Carbon::now();
                $event->effects_completed_at = $event->effect === null ? $event->applied_at : null;
                $event->save();
            }

            return [$fiscal, $event];
        });

        $this->fiscalDocument->setRawAttributes($fiscal->getAttributes(), true);

        if ($event->effects_completed_at === null) {
            $this->runEffect($fiscal, (string) $event->effect);
            $event->effects_completed_at = Carbon::now();
            $event->save();
        }

        if ($fiscal->isCurrent() && $fiscal->status === FiscalDocumentStatusEnum::PENDING && ($this->applied || $this->pollAttempt > 0)) {
            PollEcfResultJob::schedule($fiscal, $this->pollAttempt + 1);
        }

        return $fiscal;
    }

    /**
     * @return string|null the side effect owed once this commits; null when the row did not change state, or
     *                     changed on a superseded row (its history only)
     */
    private function transition(FiscalDocument $fiscal, FiscalDocumentStatusEnum $target, Carbon $answeredAt): ?string
    {
        $current = $fiscal->status;

        if ($fiscal->last_authority_update_at !== null && $answeredAt->lt($fiscal->last_authority_update_at)) {
            return null;
        }

        if ($current->isTerminal()) {
            if ($this->conflicts($current, $target)) {
                $fiscal->needs_review = true;
                $fiscal->mergeAuthorityData(['review_reason' => "Authority answered {$target->value} after {$current->value}."]);
                $fiscal->save();

                return self::EFFECT_CONFLICT;
            }

            if (! ($current->isAccepted() && $target === FiscalDocumentStatusEnum::CANCELLED)) {
                return null;
            }
        }

        $this->apply($fiscal, $target, $answeredAt);
        $this->applied = true;

        if (! $fiscal->isCurrent()) {
            return null;
        }

        return match ($target) {
            FiscalDocumentStatusEnum::ACCEPTED, FiscalDocumentStatusEnum::ACCEPTED_WITH_OBSERVATIONS => self::EFFECT_ACCEPTED,
            FiscalDocumentStatusEnum::REJECTED => self::EFFECT_REJECTED,
            FiscalDocumentStatusEnum::CANCELLED => self::EFFECT_CANCELLED,
            default => null,
        };
    }

    /**
     * Each effect is safe to run twice: the void and the PDF check what is already there.
     */
    private function runEffect(FiscalDocument $fiscal, string $effect): void
    {
        match ($effect) {
            self::EFFECT_ACCEPTED => $this->accepted($fiscal),
            self::EFFECT_REJECTED => $this->rejected($fiscal),
            self::EFFECT_CANCELLED => $this->emit($fiscal, 'ecf_cancelled', EventStatusEnum::INFO),
            self::EFFECT_CONFLICT => $this->emit($fiscal, 'ecf_conflicting_answer', EventStatusEnum::INFO),
            default => null,
        };
    }

    private function conflicts(FiscalDocumentStatusEnum $current, FiscalDocumentStatusEnum $target): bool
    {
        return ($current->isAccepted() && $target === FiscalDocumentStatusEnum::REJECTED)
            || ($current === FiscalDocumentStatusEnum::REJECTED && $target->isAccepted());
    }

    private function apply(FiscalDocument $fiscal, FiscalDocumentStatusEnum $target, Carbon $answeredAt): void
    {
        $answer = $this->answer;

        $fiscal->status = $target;
        $fiscal->authority_status = $answer->status->value;
        $fiscal->last_authority_update_at = $answeredAt;

        if ($target === FiscalDocumentStatusEnum::REJECTED) {
            $fiscal->authority_number_consumed = $answer->numberConsumed;
        }

        if ($target->isAccepted()) {
            $fiscal->authorized_at ??= $answeredAt;
            $fiscal->authority_id ??= $fiscal->number;
            // The accepting answer may lack the QR (WebPOS's getResultFe does) while an earlier one carried it.
            $fiscal->authority_seal = $answer->securityCode
                ?? $fiscal->regime()->sealFromStamp($answer->qrUrl ?? (string) $fiscal->authorityValue('qr_url'))
                ?? $fiscal->authority_seal;
            $fiscal->signed_at = DateHelper::tryParseCarbon($answer->signedAt) ?? $fiscal->signed_at;
        }

        $fiscal->mergeAuthorityData(array_filter([
            'messages' => $answer->messages,
            'signed_at' => $answer->signedAt,
            'qr_url' => $answer->qrUrl,
            'qr_legends' => $answer->qrLegends,
            'provider_ref' => $answer->providerReference,
            'poll_attempts' => max($this->pollAttempt, (int) $fiscal->authorityValue('poll_attempts')),
        ], fn (mixed $value): bool => $value !== null));
        $fiscal->save();
    }

    private function recordOnSubmission(FiscalDocument $fiscal): void
    {
        $submission = $this->submission ?? $fiscal->latestSubmission;

        if ($submission === null || ($this->submission === null && $submission->status->isSettled())) {
            return;
        }

        $submission->status = FiscalSubmissionStatusEnum::fromAnswer($this->answer->status);
        $submission->provider_ref = $this->answer->providerReference ?? $submission->provider_ref;
        $submission->messages = $this->answer->messages;
        $submission->authority_number_consumed = $this->answer->numberConsumed;
        $submission->responded_at = Carbon::now();
        $submission->save();
    }

    private function accepted(FiscalDocument $fiscal): void
    {
        $entity = $fiscal->entity;

        if ($entity instanceof Invoice || $entity instanceof Bill || $entity instanceof Expense) {
            $this->attachLegalPdf($fiscal, $entity);
        }

        $this->emit($fiscal, 'ecf_accepted', EventStatusEnum::SUCCESS);
    }

    private function rejected(FiscalDocument $fiscal): void
    {
        $this->emit($fiscal, 'ecf_rejected', EventStatusEnum::INFO);
        $entity = $fiscal->entity;

        if ($fiscal->isReusable()) {
            $this->flag($fiscal, ['action_required' => 'fix_and_resubmit_ecf']);

            return;
        }

        if (! $entity instanceof Invoice) {
            $this->flag($fiscal, ['action_required' => 'reissue_ecf']);

            return;
        }

        if ($entity->document_status === InvoiceDocumentStatusEnum::VOIDED) {
            return;
        }

        if (! in_array($entity->document_status, [InvoiceDocumentStatusEnum::ISSUED, InvoiceDocumentStatusEnum::SENT], true)) {
            $this->flag($fiscal, ['action_required' => 'reverse_' . $entity->document_type->value]);

            return;
        }

        try {
            new VoidInvoiceAction(invoice: $entity, voidReasonCode: self::VOID_REASON)->execute();
        } catch (Throwable $e) {
            report($e);
            $this->flag($fiscal, ['action_required' => 'reverse_' . $entity->document_type->value, 'void_error' => $e->getMessage()]);
        }
    }

    /**
     * @param array<string, mixed> $changes
     */
    private function flag(FiscalDocument $fiscal, array $changes): void
    {
        $fiscal->mergeAuthorityData($changes);
        $fiscal->save();
    }

    /**
     * Best-effort: losing the printed copy must not lose the status recorded above. Our own render is queued;
     * the provider's copy is already in hand and stored now.
     */
    private function attachLegalPdf(FiscalDocument $fiscal, Invoice|Bill|Expense $document): void
    {
        $user = $document->user ?? $document->company?->user;

        $hasLegalPdf = $document->getFileByName(self::PDF_FIELD_NAME) !== null
            || $document->getFileByName(FiscalRegimeInterface::INVOICE_PDF_FIELD) !== null;

        if (! $user instanceof Users || $hasLegalPdf) {
            return;
        }

        $preferOurs = $document instanceof Invoice
            && EcfPdfSourceEnum::forCompany($document->company, $fiscal->provider) === EcfPdfSourceEnum::KANVAS;

        if (! $preferOurs && $this->answer->pdfBase64 !== null && $this->storeProviderPdf($fiscal, $document, $user)) {
            return;
        }

        if ($document instanceof Invoice) {
            GenerateEcfPdfJob::dispatch($document->app, $document, $user)->afterCommit();
        }
    }

    private function storeProviderPdf(FiscalDocument $fiscal, Invoice|Bill|Expense $document, Users $user): bool
    {
        $bytes = base64_decode((string) $this->answer->pdfBase64, true);

        if ($bytes === false || $bytes === '') {
            return false;
        }

        try {
            $file = new FilesystemServices($document->app, $document->company)->uploadContents(
                $bytes,
                'e-cf-' . $fiscal->number . '.pdf',
                $user
            );

            $document->addFile($file, self::PDF_FIELD_NAME);

            return true;
        } catch (Throwable $e) {
            report($e);

            return false;
        }
    }

    private function emit(FiscalDocument $fiscal, string $event, EventStatusEnum $status): void
    {
        $entity = $fiscal->entity;

        if (! $entity instanceof Invoice && ! $entity instanceof Bill && ! $entity instanceof Expense) {
            return;
        }

        $entity->emitLedgerEvent(
            eventType: EcfCompliance::eventName($entity, $event),
            status: $status,
            payload: [
                'invoice_number' => $entity instanceof Invoice ? $entity->invoice_number : null,
                'fiscal_document_id' => $fiscal->getKey(),
                'encf' => $fiscal->number,
                'authority_id' => $fiscal->authority_id,
                'type' => $fiscal->document_type,
                'provider' => $fiscal->provider,
                'provider_ref' => $this->answer->providerReference,
                'status' => $fiscal->status->toEcfStatus()->value,
                'messages' => $this->answer->messages,
                'burned' => $fiscal->isBurned(),
                'number_consumed' => $fiscal->authority_number_consumed,
            ],
        );
    }

    /**
     * Content hash, not body hash: a webhook and a poll carrying the same answer must collide. A send's own
     * answer is keyed to its attempt, so a resubmission answered exactly like the last one is still applied.
     */
    private function dedupeKey(): string
    {
        $scope = $this->submission !== null ? 'attempt:' . $this->submission->attempt : 'inbound';

        return 'answer:' . hash('sha256', $this->fiscalDocument->uuid . '|' . $scope . '|' . json_encode($this->answerPayload()));
    }

    /**
     * @return array<string, mixed>
     */
    private function answerPayload(): array
    {
        $payload = $this->answer->toArray();
        unset($payload['pdfBase64'], $payload['wasDuplicated'], $payload['rawResponse']);

        return $payload;
    }
}
