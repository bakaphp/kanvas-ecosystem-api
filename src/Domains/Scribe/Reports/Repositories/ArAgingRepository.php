<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Reports\Repositories;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Support\Money;
use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Kanvas\Scribe\Invoices\Enums\AgingBucketEnum;
use Kanvas\Scribe\Invoices\Enums\AllocationStatusEnum;
use Kanvas\Scribe\Invoices\Enums\DocumentTypeEnum;
use Kanvas\Scribe\Invoices\Enums\InvoiceDocumentStatusEnum;
use Kanvas\Scribe\Invoices\Models\Invoice;
use Kanvas\Scribe\Invoices\Models\InvoicePaymentAllocation;
use Kanvas\Scribe\Reports\DataTransferObject\ArAgingData;
use Kanvas\Scribe\Reports\DataTransferObject\ArAgingRow;
use Spatie\LaravelData\DataCollection;

/**
 * Open-AR aging snapshot grouped by customer Organization, with the outstanding balance
 * bucketed by `AgingBucketEnum::forInvoice($invoice->due_date, $asOf)`.
 *
 * Only includes invoices in document_status ∈ {ISSUED, SENT} with balance_due_base > 0. Drafts, voided,
 * and credit_notes are excluded. Asked for a past day, it is the receivables as they stood that day.
 */
class ArAgingRepository
{
    public function generate(
        AppInterface $app,
        CompanyInterface $company,
        Carbon $asOf,
        string $currency = 'USD',
    ): ArAgingData {
        $byCustomer = [];
        $totals = [
            AgingBucketEnum::CURRENT->value => 0.0,
            AgingBucketEnum::BUCKET_1_30->value => 0.0,
            AgingBucketEnum::BUCKET_31_60->value => 0.0,
            AgingBucketEnum::BUCKET_61_90->value => 0.0,
            AgingBucketEnum::BUCKET_90_PLUS->value => 0.0,
        ];

        foreach ($this->owedAsOf($app, $company, $asOf) as [$invoice, $balance]) {
            $bucket = AgingBucketEnum::forInvoice($invoice->due_date, $asOf);
            $orgId = $invoice->customer_organization_id !== null ? (int) $invoice->customer_organization_id : 0;
            $key = (string) $orgId;

            if (! isset($byCustomer[$key])) {
                $byCustomer[$key] = [
                    'customer_type' => 'organization',
                    'customer_id' => $orgId === 0 ? null : $orgId,
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

    /**
     * What each receivable owed on the day asked for. Today that is its stored balance. A past day is rebuilt:
     * the invoices issued and not voided by then, less the payments allocated by then; one paid before that day
     * and still paid is left out up front.
     *
     * @return list<array{Invoice, float}>
     */
    private function owedAsOf(AppInterface $app, CompanyInterface $company, Carbon $asOf): array
    {
        $cutoff = $asOf->copy()->endOfDay();
        $receivables = Invoice::query()
            ->fromApp($app)
            ->fromCompany($company)
            ->notDeleted()
            ->whereIn('document_type', DocumentTypeEnum::receivables());

        if (! $cutoff->isPast()) {
            return $receivables
                ->whereIn('document_status', [
                    InvoiceDocumentStatusEnum::ISSUED,
                    InvoiceDocumentStatusEnum::SENT,
                ])
                ->where('balance_due_base', '>', 0.005)
                ->get()
                ->map(static fn (Invoice $invoice): array => [$invoice, (float) $invoice->balance_due_base])
                ->all();
        }

        $invoices = $receivables
            ->where('document_status', '!=', InvoiceDocumentStatusEnum::DRAFT)
            ->whereDate('issued_date', '<=', $asOf)
            ->where(
                static fn (Builder $query): Builder => $query
                    ->where('document_status', '!=', InvoiceDocumentStatusEnum::VOIDED)
                    ->orWhere('voided_at', '>', $cutoff)
            )
            ->where(
                static fn (Builder $query): Builder => $query
                    ->where('document_status', '!=', InvoiceDocumentStatusEnum::PAID)
                    ->orWhereNull('paid_at')
                    ->orWhere('paid_at', '>', $cutoff)
            )
            ->get();
        // Counted as it stood that day: an allocation reversed later still paid the invoice then, and one made
        // later did not yet.
        $allocations = InvoicePaymentAllocation::query()
            ->whereIn('invoice_id', $invoices->modelKeys())
            ->groupBy('invoice_id')
            ->selectRaw(
                'invoice_id,'
                . ' SUM(CASE WHEN allocated_at <= ? AND (status = ? OR reversed_at > ?) THEN amount_base ELSE 0 END) AS paid_by_then,'
                . ' SUM(CASE WHEN status = ? THEN amount_base ELSE 0 END) AS paid_now',
                [
                    $cutoff,
                    AllocationStatusEnum::ACTIVE->value,
                    $cutoff,
                    AllocationStatusEnum::ACTIVE->value,
                ]
            )
            ->get()
            ->keyBy('invoice_id');

        return $invoices
            ->map(static function (Invoice $invoice) use ($allocations, $cutoff): array {
                $paidByThen = Money::of($allocations[$invoice->id]->paid_by_then ?? 0);
                // An imported invoice's payments stay in the system it came from, which the import notes with
                // their day. The source's figure is the invoice's paid amount as a whole, so it is not added to
                // the allocations. With no note at all (imported before they were kept), what the invoice says
                // is paid with no allocation behind it counts as already paid.
                $reported = $invoice->externalPaidAsOf($cutoff);
                $paid = $reported !== null
                    ? BigDecimal::max($paidByThen, Money::of($reported))
                    : $paidByThen->plus(BigDecimal::max(
                        0,
                        Money::of($invoice->paid_base ?? 0)
                            ->minus(Money::of($allocations[$invoice->id]->paid_now ?? 0))
                    ));

                return [$invoice, Money::stored(Money::of((float) $invoice->total_base)->minus($paid))];
            })
            ->filter(static fn (array $owed): bool => $owed[1] > 0.005)
            ->values()
            ->all();
    }
}
