<?php

declare(strict_types=1);

namespace Kanvas\Scribe\PdfIngest\Actions;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Users\Contracts\UserInterface;
use Illuminate\Support\Facades\Log;
use Kanvas\Filesystem\Models\Filesystem;
use Kanvas\Scribe\PdfIngest\Enums\PdfIngestStatusEnum;
use Kanvas\Scribe\PdfIngest\Models\PdfIngestLog;

/**
 * Re-routes PR 9-era vendor_invoice PDFs that were logged with status=AWAITING_BILL_SUPPORT
 * (because the Bills sub-ledger wasn't live yet) through the new Bill flow.
 *
 * Each candidate row:
 *   1. Loads the original Filesystem + stored extracted_payload from the log
 *   2. Calls ProposeBillFromPdfAction → writes a draft Bill
 *   3. Updates the log row: status=ENTITY_CREATED, linked_entity_type=bill, linked_entity_id=<bill_id>
 *
 * Idempotent — if a row already has linked_entity_id set (someone manually wired it earlier), skip it.
 *
 * Run as a one-shot per (app, company) after PR 10 migration deploys, e.g.:
 *   php artisan tinker
 *   new BackfillPdfIngestedBillsAction(app(Apps::class), $company, $aiAgentUser)->execute();
 *
 * Returns counts so the operator can verify.
 */
class BackfillPdfIngestedBillsAction
{
    public function __construct(
        public readonly AppInterface $app,
        public readonly CompanyInterface $company,
        public readonly ?UserInterface $user = null,
    ) {
    }

    /**
     * @return array{candidates: int, backfilled: int, skipped: int, failed: int}
     */
    public function execute(): array
    {
        $candidates = PdfIngestLog::query()
            ->where('apps_id', $this->app->getId())
            ->where('companies_id', $this->company->getId())
            ->where('status', PdfIngestStatusEnum::AWAITING_BILL_SUPPORT)
            ->whereNull('linked_entity_id')
            ->orderBy('id')
            ->get();

        $stats = [
            'candidates' => $candidates->count(),
            'backfilled' => 0,
            'skipped' => 0,
            'failed' => 0,
        ];

        foreach ($candidates as $log) {
            $pdf = Filesystem::query()->where('id', $log->filesystem_id)->first();
            if ($pdf === null) {
                Log::warning('Scribe.PdfIngest.BackfillBills skipped — Filesystem row missing', [
                    'pdf_ingest_log_id' => $log->id,
                    'filesystem_id' => $log->filesystem_id,
                ]);
                $stats['skipped'] += 1;

                continue;
            }

            $extracted = $log->extracted_payload ?? [];
            if (! is_array($extracted) || $extracted === []) {
                Log::warning('Scribe.PdfIngest.BackfillBills skipped — no extracted_payload', [
                    'pdf_ingest_log_id' => $log->id,
                ]);
                $stats['skipped'] += 1;

                continue;
            }

            $bill = new ProposeBillFromPdfAction(
                app: $this->app,
                company: $this->company,
                pdf: $pdf,
                extracted: $extracted,
                pdfIngestLogId: (int) $log->id,
                user: $this->user,
                fromEmail: $log->from_email,
            )->execute();

            if ($bill === null) {
                $stats['failed'] += 1;

                continue;
            }

            $log->status = PdfIngestStatusEnum::ENTITY_CREATED;
            $log->linked_entity_type = 'bill';
            $log->linked_entity_id = (int) $bill->id;
            $log->rejected_reason = null;
            $log->save();

            $stats['backfilled'] += 1;
        }

        return $stats;
    }
}
