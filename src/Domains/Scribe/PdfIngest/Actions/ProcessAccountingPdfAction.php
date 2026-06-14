<?php

declare(strict_types=1);

namespace Kanvas\Scribe\PdfIngest\Actions;

use Baka\Users\Contracts\UserInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Kanvas\Scribe\PdfIngest\Contracts\PdfClassifierServiceInterface;
use Kanvas\Scribe\PdfIngest\Contracts\PdfContentHasherInterface;
use Kanvas\Scribe\PdfIngest\DataTransferObject\PdfIngestInput;
use Kanvas\Scribe\PdfIngest\Enums\PdfIngestDocumentTypeEnum;
use Kanvas\Scribe\PdfIngest\Enums\PdfIngestStatusEnum;
use Kanvas\Scribe\PdfIngest\Models\PdfIngestLog;
use Kanvas\Scribe\PdfIngest\Services\GeminiPdfClassifierService;
use Kanvas\Scribe\PdfIngest\Services\RemotePdfContentHasherService;
use Throwable;

/**
 * The orchestrator. One inbound PDF → one PdfIngestLog row → optionally one Scribe entity.
 *
 * Idempotent on (apps_id, message_id) — re-running with the same message_id returns the existing log
 * row instead of double-processing. Mailgun sometimes redelivers, so this matters.
 *
 * Routing matrix (per the PR 9 design call — Option C):
 *
 *   expense_receipt      → ProposeExpenseFromPdfAction → draft Expense + attached receipt
 *                          status=entity_created
 *   vendor_invoice       → log only, status=awaiting_bill_support (PR 10 backfills these)
 *   vendor_quote         → log only, status=quote_inbound_logged
 *   our_invoice/our_quote → log only, status=ignored_our_doc
 *   unknown              → log only, status=rejected_unknown + rejected_reason
 *   (classifier throws)  → log only, status=failed + rejected_reason
 *
 * All routing decisions happen inside one accounting-DB transaction so the log row is always written
 * with the entity link consistent.
 */
class ProcessAccountingPdfAction
{
    public function __construct(
        public readonly PdfIngestInput $input,
        public readonly ?UserInterface $user = null,
        protected readonly ?PdfClassifierServiceInterface $classifier = null,
        protected readonly ?PdfContentHasherInterface $hasher = null,
    ) {
    }

    public function execute(): PdfIngestLog
    {
        $existing = $this->findExistingByMessageId();
        if ($existing !== null) {
            return $existing;
        }

        // Path 2 — content hash dedup. Compute once, check against prior ingests; if we've already
        // processed this exact PDF (regardless of how it arrived), short-circuit and link the new
        // log row to the pre-existing entity.
        $contentHash = $this->resolveHasher()->hash($this->input->pdf);
        $priorWithSameHash = $this->findPriorWithSameHash($contentHash);

        $log = $this->createPendingLog();
        $log->content_sha256 = $contentHash;

        if ($priorWithSameHash !== null) {
            $log->status = PdfIngestStatusEnum::IGNORED_DUPLICATE;
            $log->document_type = $priorWithSameHash->document_type;
            $log->confidence = (float) $priorWithSameHash->confidence;
            $log->extracted_payload = $priorWithSameHash->extracted_payload;
            $log->linked_entity_type = $priorWithSameHash->linked_entity_type;
            $log->linked_entity_id = $priorWithSameHash->linked_entity_id;
            $log->rejected_reason = "Duplicate of pdf_ingest_log #{$priorWithSameHash->id} (same content hash). "
                . 'Linked to the existing entity rather than re-creating.';
            $log->processed_at = Carbon::now();
            $log->save();

            return $log->refresh();
        }

        try {
            $result = $this->resolveClassifier()->classify(
                pdf: $this->input->pdf,
                hints: [
                    'from_email' => $this->input->fromEmail,
                    'from_name' => $this->input->fromName,
                    'subject' => $this->input->subject,
                ],
            );
        } catch (Throwable $e) {
            Log::error('Scribe.PdfIngest classifier failed', [
                'pdf_ingest_log_id' => $log->id,
                'exception' => $e->getMessage(),
            ]);
            $log->status = PdfIngestStatusEnum::FAILED;
            $log->rejected_reason = 'Classifier failed: ' . $e->getMessage();
            $log->processed_at = Carbon::now();
            $log->save();

            return $log->refresh();
        }

        return DB::connection('accounting')->transaction(function () use ($log, $result): PdfIngestLog {
            $log->document_type = $result->document_type;
            $log->confidence = $result->confidence;
            $log->extracted_payload = $result->extracted;
            $log->classification_reasoning = $result->reasoning;

            $this->routeByDocumentType($log, $result->extracted ?? []);

            $log->processed_at = Carbon::now();
            $log->save();

            return $log->refresh();
        });
    }

    private function findExistingByMessageId(): ?PdfIngestLog
    {
        if ($this->input->messageId === null || $this->input->messageId === '') {
            return null;
        }

        return PdfIngestLog::query()
            ->where('apps_id', $this->input->app->getId())
            ->where('companies_id', $this->input->company->getId())
            ->where('message_id', $this->input->messageId)
            ->first();
    }

    /**
     * Path 2 — find a prior log row for this tenant with the same content hash that produced a
     * usable outcome (created an entity or logged as informational). FAILED + REJECTED_UNKNOWN
     * rows are NOT considered duplicates — re-processing them might succeed.
     */
    private function findPriorWithSameHash(string $contentHash): ?PdfIngestLog
    {
        return PdfIngestLog::query()
            ->where('apps_id', $this->input->app->getId())
            ->where('companies_id', $this->input->company->getId())
            ->where('content_sha256', $contentHash)
            ->whereNotIn('status', [
                PdfIngestStatusEnum::FAILED->value,
                PdfIngestStatusEnum::REJECTED_UNKNOWN->value,
                PdfIngestStatusEnum::PENDING->value,
            ])
            ->orderBy('id')
            ->first();
    }

    private function createPendingLog(): PdfIngestLog
    {
        $log = new PdfIngestLog();
        $log->apps_id = $this->input->app->getId();
        $log->companies_id = $this->input->company->getId();
        $log->filesystem_id = (int) $this->input->pdf->getKey();
        $log->message_id = $this->input->messageId;
        $log->from_email = $this->input->fromEmail;
        $log->from_name = $this->input->fromName;
        $log->subject = $this->input->subject;
        $log->inbound_metadata = $this->input->inboundMetadata;
        $log->document_type = PdfIngestDocumentTypeEnum::UNKNOWN;
        $log->confidence = 0.0;
        $log->status = PdfIngestStatusEnum::PENDING;
        $log->users_id = $this->user?->getId();
        $log->save();

        return $log;
    }

    /**
     * @param  array<string, mixed>  $extracted
     */
    private function routeByDocumentType(PdfIngestLog $log, array $extracted): void
    {
        switch ($log->document_type) {
            case PdfIngestDocumentTypeEnum::EXPENSE_RECEIPT:
                $this->routeExpenseReceipt($log, $extracted);

                return;
            case PdfIngestDocumentTypeEnum::VENDOR_INVOICE:
                $this->routeVendorInvoice($log, $extracted);

                return;
            case PdfIngestDocumentTypeEnum::VENDOR_QUOTE:
                $log->status = PdfIngestStatusEnum::QUOTE_INBOUND_LOGGED;
                $log->rejected_reason = 'Inbound vendor quote — no GL entity created (informational only).';

                return;
            case PdfIngestDocumentTypeEnum::OUR_INVOICE:
            case PdfIngestDocumentTypeEnum::OUR_QUOTE:
                $log->status = PdfIngestStatusEnum::IGNORED_OUR_DOC;
                $log->rejected_reason = 'This is one of our outgoing documents — already in DB. Forwarded copy ignored.';

                return;
            case PdfIngestDocumentTypeEnum::UNKNOWN:
            default:
                $log->status = PdfIngestStatusEnum::REJECTED_UNKNOWN;
                $log->rejected_reason = $log->rejected_reason
                    ?? 'Document could not be classified as an accounting document.';

                return;
        }
    }

    /**
     * @param  array<string, mixed>  $extracted
     */
    private function routeVendorInvoice(PdfIngestLog $log, array $extracted): void
    {
        $bill = new ProposeBillFromPdfAction(
            app: $this->input->app,
            company: $this->input->company,
            pdf: $this->input->pdf,
            extracted: $extracted,
            pdfIngestLogId: (int) $log->id,
            user: $this->user,
            fromEmail: $this->input->fromEmail,
        )->execute();

        if ($bill === null) {
            $log->status = PdfIngestStatusEnum::FAILED;
            $log->rejected_reason = 'Bill creation failed — extracted payload missing required fields.';

            return;
        }

        $log->status = PdfIngestStatusEnum::ENTITY_CREATED;
        $log->linked_entity_type = 'bill';
        $log->linked_entity_id = (int) $bill->id;
    }

    /**
     * @param  array<string, mixed>  $extracted
     */
    private function routeExpenseReceipt(PdfIngestLog $log, array $extracted): void
    {
        $expense = new ProposeExpenseFromPdfAction(
            app: $this->input->app,
            company: $this->input->company,
            pdf: $this->input->pdf,
            extracted: $extracted,
            user: $this->user,
            fromEmail: $this->input->fromEmail,
        )->execute();

        if ($expense === null) {
            $log->status = PdfIngestStatusEnum::FAILED;
            $log->rejected_reason = 'Expense creation failed — extracted payload missing required fields.';

            return;
        }

        $log->status = PdfIngestStatusEnum::ENTITY_CREATED;
        $log->linked_entity_type = 'expense';
        $log->linked_entity_id = (int) $expense->id;
    }

    private function resolveClassifier(): PdfClassifierServiceInterface
    {
        if ($this->classifier !== null) {
            return $this->classifier;
        }
        if (app()->bound(PdfClassifierServiceInterface::class)) {
            return app(PdfClassifierServiceInterface::class);
        }

        return new GeminiPdfClassifierService();
    }

    private function resolveHasher(): PdfContentHasherInterface
    {
        if ($this->hasher !== null) {
            return $this->hasher;
        }
        if (app()->bound(PdfContentHasherInterface::class)) {
            return app(PdfContentHasherInterface::class);
        }

        return new RemotePdfContentHasherService();
    }
}
