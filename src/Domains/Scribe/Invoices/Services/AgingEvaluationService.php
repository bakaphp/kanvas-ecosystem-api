<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Invoices\Services;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Illuminate\Support\Carbon;
use Kanvas\Scribe\Invoices\Enums\AgingBucketEnum;
use Kanvas\Scribe\Invoices\Enums\DocumentTypeEnum;
use Kanvas\Scribe\Invoices\Enums\InvoiceCollectionStateEnum;
use Kanvas\Scribe\Invoices\Enums\InvoiceDocumentStatusEnum;
use Kanvas\Scribe\Invoices\Models\Invoice;

/**
 * Walks every open invoice for an (app, company) and updates `collection_state` based on aging bucket.
 *
 * Rules:
 *   - CURRENT bucket → collection_state = CURRENT
 *   - Any overdue bucket (1-30, 31-60, 61-90, 90+) → collection_state = OVERDUE
 *   - Disputed / Uncollectible are NOT touched (those are manual operator decisions)
 *
 * Returns a small report so callers can log / emit metrics on how much state changed.
 */
class AgingEvaluationService
{
    /**
     * @return array{evaluated: int, transitioned: int, by_bucket: array<string, int>}
     */
    public function evaluate(
        AppInterface $app,
        CompanyInterface $company,
        ?Carbon $today = null,
    ): array {
        $today ??= Carbon::today();
        $evaluated = 0;
        $transitioned = 0;
        $byBucket = [
            AgingBucketEnum::CURRENT->value => 0,
            AgingBucketEnum::BUCKET_1_30->value => 0,
            AgingBucketEnum::BUCKET_31_60->value => 0,
            AgingBucketEnum::BUCKET_61_90->value => 0,
            AgingBucketEnum::BUCKET_90_PLUS->value => 0,
        ];

        Invoice::query()
            ->where('apps_id', $app->getId())
            ->where('companies_id', $company->getId())
            ->where('document_type', DocumentTypeEnum::INVOICE)
            ->whereIn('document_status', [
                InvoiceDocumentStatusEnum::ISSUED,
                InvoiceDocumentStatusEnum::SENT,
            ])
            ->where('balance_due_base', '>', 0.005)
            ->where('is_deleted', false)
            ->chunkById(200, function ($chunk) use ($today, &$evaluated, &$transitioned, &$byBucket): void {
                foreach ($chunk as $invoice) {
                    $evaluated += 1;
                    $bucket = AgingBucketEnum::forInvoice($invoice->due_date, $today);
                    $byBucket[$bucket->value] += 1;

                    $newState = $bucket === AgingBucketEnum::CURRENT
                        ? InvoiceCollectionStateEnum::CURRENT
                        : InvoiceCollectionStateEnum::OVERDUE;

                    if ($invoice->collection_state === InvoiceCollectionStateEnum::DISPUTED
                        || $invoice->collection_state === InvoiceCollectionStateEnum::UNCOLLECTIBLE) {
                        continue;
                    }

                    if ($invoice->collection_state !== $newState) {
                        $invoice->collection_state = $newState;
                        $invoice->save();
                        $transitioned += 1;
                    }
                }
            });

        return [
            'evaluated' => $evaluated,
            'transitioned' => $transitioned,
            'by_bucket' => $byBucket,
        ];
    }
}
