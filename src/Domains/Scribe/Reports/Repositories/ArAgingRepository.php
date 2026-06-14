<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Reports\Repositories;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Illuminate\Support\Carbon;
use Kanvas\Scribe\Invoices\Enums\AgingBucketEnum;
use Kanvas\Scribe\Invoices\Enums\DocumentTypeEnum;
use Kanvas\Scribe\Invoices\Enums\InvoiceDocumentStatusEnum;
use Kanvas\Scribe\Invoices\Models\Invoice;
use Kanvas\Scribe\Reports\DataTransferObject\ArAgingData;
use Kanvas\Scribe\Reports\DataTransferObject\ArAgingRow;
use Spatie\LaravelData\DataCollection;

/**
 * Open-AR aging snapshot grouped by customer (billable_type + billable_id), with the outstanding balance
 * bucketed by `AgingBucketEnum::forInvoice($invoice->due_date, $asOf)`.
 *
 * Only includes invoices in document_status ∈ {ISSUED, SENT} with balance_due_base > 0. Drafts, voided,
 * and credit_notes are excluded.
 */
class ArAgingRepository
{
    public function generate(
        AppInterface $app,
        CompanyInterface $company,
        Carbon $asOf,
        string $currency = 'USD',
    ): ArAgingData {
        $openInvoices = Invoice::query()
            ->fromApp($app)
            ->fromCompany($company)
            ->notDeleted()
            ->where('document_type', DocumentTypeEnum::INVOICE)
            ->whereIn('document_status', [
                InvoiceDocumentStatusEnum::ISSUED,
                InvoiceDocumentStatusEnum::SENT,
            ])
            ->where('balance_due_base', '>', 0.005)
            ->get();

        $byCustomer = [];
        $totals = [
            AgingBucketEnum::CURRENT->value => 0.0,
            AgingBucketEnum::BUCKET_1_30->value => 0.0,
            AgingBucketEnum::BUCKET_31_60->value => 0.0,
            AgingBucketEnum::BUCKET_61_90->value => 0.0,
            AgingBucketEnum::BUCKET_90_PLUS->value => 0.0,
        ];

        foreach ($openInvoices as $invoice) {
            $bucket = AgingBucketEnum::forInvoice($invoice->due_date, $asOf);
            $balance = (float) $invoice->balance_due_base;
            $key = ($invoice->billable_type ?? '_unbilled') . '|' . ($invoice->billable_id ?? 0);

            if (! isset($byCustomer[$key])) {
                $byCustomer[$key] = [
                    'customer_type' => $invoice->billable_type,
                    'customer_id' => $invoice->billable_id !== null ? (int) $invoice->billable_id : null,
                    'customer_name' => (string) ($invoice->billable_display_name ?? 'Unknown'),
                    AgingBucketEnum::CURRENT->value => 0.0,
                    AgingBucketEnum::BUCKET_1_30->value => 0.0,
                    AgingBucketEnum::BUCKET_31_60->value => 0.0,
                    AgingBucketEnum::BUCKET_61_90->value => 0.0,
                    AgingBucketEnum::BUCKET_90_PLUS->value => 0.0,
                ];
            }

            $byCustomer[$key][$bucket->value] += $balance;
            $totals[$bucket->value] += $balance;
        }

        $rows = [];
        foreach ($byCustomer as $row) {
            $total = $row[AgingBucketEnum::CURRENT->value]
                + $row[AgingBucketEnum::BUCKET_1_30->value]
                + $row[AgingBucketEnum::BUCKET_31_60->value]
                + $row[AgingBucketEnum::BUCKET_61_90->value]
                + $row[AgingBucketEnum::BUCKET_90_PLUS->value];

            $rows[] = new ArAgingRow(
                customer_type: $row['customer_type'],
                customer_id: $row['customer_id'],
                customer_name: $row['customer_name'],
                current: (float) $row[AgingBucketEnum::CURRENT->value],
                bucket_1_30: (float) $row[AgingBucketEnum::BUCKET_1_30->value],
                bucket_31_60: (float) $row[AgingBucketEnum::BUCKET_31_60->value],
                bucket_61_90: (float) $row[AgingBucketEnum::BUCKET_61_90->value],
                bucket_90_plus: (float) $row[AgingBucketEnum::BUCKET_90_PLUS->value],
                total: $total,
            );
        }

        usort($rows, fn (ArAgingRow $a, ArAgingRow $b) => $b->total <=> $a->total);

        return new ArAgingData(
            as_of: $asOf,
            currency: $currency,
            rows: new DataCollection(ArAgingRow::class, $rows),
            total_current: $totals[AgingBucketEnum::CURRENT->value],
            total_1_30: $totals[AgingBucketEnum::BUCKET_1_30->value],
            total_31_60: $totals[AgingBucketEnum::BUCKET_31_60->value],
            total_61_90: $totals[AgingBucketEnum::BUCKET_61_90->value],
            total_90_plus: $totals[AgingBucketEnum::BUCKET_90_PLUS->value],
            grand_total: array_sum($totals),
        );
    }
}
