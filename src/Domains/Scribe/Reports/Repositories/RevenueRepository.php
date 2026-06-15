<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Reports\Repositories;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Kanvas\Scribe\Invoices\Enums\DocumentTypeEnum;
use Kanvas\Scribe\Invoices\Enums\InvoiceDocumentStatusEnum;
use Kanvas\Scribe\Invoices\Models\Invoice;
use Kanvas\Scribe\Reports\DataTransferObject\RevenueReportData;
use Kanvas\Scribe\Reports\DataTransferObject\RevenueRow;
use Kanvas\Scribe\Reports\Enums\RevenueGroupByEnum;
use Spatie\LaravelData\DataCollection;

/**
 * Revenue breakdown for [period_start, period_end].
 *
 * Source of truth: Invoices in document_status ∈ {ISSUED, SENT, PAID} with issued_date in the window.
 * Credit notes are subtracted as discounts when their issued_date falls in the window AND their parent
 * invoice's issued_date also falls in (or before) the window.
 *
 * Three grouping modes:
 *   - CUSTOMER: one row per customer_organization_id with their display name
 *   - MONTH:    one row per YYYY-MM
 *   - ITEM:     one row per item_id (with NULL items grouped as "Uncategorized")
 *
 * Gross revenue = subtotal_base (pre-discount). Net = gross - discount. Tax is excluded — this report is
 * about earned revenue, not customer-facing totals.
 */
class RevenueRepository
{
    public function generate(
        AppInterface $app,
        CompanyInterface $company,
        Carbon $periodStart,
        Carbon $periodEnd,
        RevenueGroupByEnum $groupBy = RevenueGroupByEnum::CUSTOMER,
        string $currency = 'USD',
    ): RevenueReportData {
        $invoices = Invoice::query()
            ->fromApp($app)
            ->fromCompany($company)
            ->notDeleted()
            ->where('document_type', DocumentTypeEnum::INVOICE)
            ->whereIn('document_status', [
                InvoiceDocumentStatusEnum::ISSUED,
                InvoiceDocumentStatusEnum::SENT,
                InvoiceDocumentStatusEnum::PAID,
            ])
            ->whereDate('issued_date', '>=', $periodStart)
            ->whereDate('issued_date', '<=', $periodEnd)
            ->with('lines')
            ->get();

        $creditNotes = Invoice::query()
            ->fromApp($app)
            ->fromCompany($company)
            ->notDeleted()
            ->where('document_type', DocumentTypeEnum::CREDIT_NOTE)
            ->whereDate('issued_date', '>=', $periodStart)
            ->whereDate('issued_date', '<=', $periodEnd)
            ->get();

        return match ($groupBy) {
            RevenueGroupByEnum::CUSTOMER => $this->groupByCustomer($invoices, $creditNotes, $periodStart, $periodEnd, $currency),
            RevenueGroupByEnum::MONTH => $this->groupByMonth($invoices, $creditNotes, $periodStart, $periodEnd, $currency),
            RevenueGroupByEnum::ITEM => $this->groupByItem($invoices, $creditNotes, $periodStart, $periodEnd, $currency),
        };
    }

    /**
     * @param  Collection<int, Invoice>  $invoices
     * @param  Collection<int, Invoice>  $creditNotes
     */
    private function groupByCustomer(
        Collection $invoices,
        Collection $creditNotes,
        Carbon $periodStart,
        Carbon $periodEnd,
        string $currency,
    ): RevenueReportData {
        $buckets = [];

        foreach ($invoices as $invoice) {
            $key = (string) ($invoice->customer_organization_id ?? 0);
            $label = (string) ($invoice->billable_display_name ?? 'Unknown');
            $buckets[$key] ??= ['label' => $label, 'gross' => 0.0, 'discounts' => 0.0, 'count' => 0];
            $buckets[$key]['gross'] += (float) $invoice->subtotal_base - (float) $invoice->discount_base;
            $buckets[$key]['count'] += 1;
        }

        foreach ($creditNotes as $cn) {
            $key = (string) ($cn->customer_organization_id ?? 0);
            $label = (string) ($cn->billable_display_name ?? 'Unknown');
            $buckets[$key] ??= ['label' => $label, 'gross' => 0.0, 'discounts' => 0.0, 'count' => 0];
            $buckets[$key]['discounts'] += (float) $cn->subtotal_base - (float) $cn->discount_base;
        }

        return $this->finalize($buckets, $periodStart, $periodEnd, $currency, 'customer');
    }

    /**
     * @param  Collection<int, Invoice>  $invoices
     * @param  Collection<int, Invoice>  $creditNotes
     */
    private function groupByMonth(
        Collection $invoices,
        Collection $creditNotes,
        Carbon $periodStart,
        Carbon $periodEnd,
        string $currency,
    ): RevenueReportData {
        $buckets = [];

        foreach ($invoices as $invoice) {
            $key = ($invoice->issued_date ?? $periodStart)->format('Y-m');
            $buckets[$key] ??= ['label' => $key, 'gross' => 0.0, 'discounts' => 0.0, 'count' => 0];
            $buckets[$key]['gross'] += (float) $invoice->subtotal_base - (float) $invoice->discount_base;
            $buckets[$key]['count'] += 1;
        }
        foreach ($creditNotes as $cn) {
            $key = ($cn->issued_date ?? $periodStart)->format('Y-m');
            $buckets[$key] ??= ['label' => $key, 'gross' => 0.0, 'discounts' => 0.0, 'count' => 0];
            $buckets[$key]['discounts'] += (float) $cn->subtotal_base - (float) $cn->discount_base;
        }

        ksort($buckets);

        return $this->finalize($buckets, $periodStart, $periodEnd, $currency, 'month');
    }

    /**
     * @param  Collection<int, Invoice>  $invoices
     * @param  Collection<int, Invoice>  $creditNotes
     */
    private function groupByItem(
        Collection $invoices,
        Collection $creditNotes,
        Carbon $periodStart,
        Carbon $periodEnd,
        string $currency,
    ): RevenueReportData {
        $buckets = [];

        foreach ($invoices as $invoice) {
            foreach ($invoice->lines as $line) {
                $key = $line->item_id !== null ? (string) $line->item_id : '_uncategorized';
                $label = (string) ($line->description ?? 'Uncategorized');
                $buckets[$key] ??= ['label' => $label, 'gross' => 0.0, 'discounts' => 0.0, 'count' => 0];
                $lineRevenue = (float) $line->line_total_base
                    - (float) $line->tax_amount_base;
                $buckets[$key]['gross'] += $lineRevenue;
                $buckets[$key]['count'] += 1;
            }
        }
        foreach ($creditNotes as $cn) {
            $cn->load('lines');
            foreach ($cn->lines as $line) {
                $key = $line->item_id !== null ? (string) $line->item_id : '_uncategorized';
                $label = (string) ($line->description ?? 'Uncategorized');
                $buckets[$key] ??= ['label' => $label, 'gross' => 0.0, 'discounts' => 0.0, 'count' => 0];
                $buckets[$key]['discounts'] += (float) $line->line_total_base - (float) $line->tax_amount_base;
            }
        }

        return $this->finalize($buckets, $periodStart, $periodEnd, $currency, 'item');
    }

    /**
     * @param  array<string, array{label: string, gross: float, discounts: float, count: int}>  $buckets
     */
    private function finalize(
        array $buckets,
        Carbon $periodStart,
        Carbon $periodEnd,
        string $currency,
        string $groupBy,
    ): RevenueReportData {
        $rows = [];
        $totalGross = 0.0;
        $totalDiscounts = 0.0;
        $totalCount = 0;

        foreach ($buckets as $key => $b) {
            $net = $b['gross'] - $b['discounts'];
            $rows[] = new RevenueRow(
                group_key: (string) $key,
                group_label: $b['label'],
                gross_revenue: $b['gross'],
                discounts: $b['discounts'],
                net_revenue: $net,
                invoice_count: $b['count'],
            );
            $totalGross += $b['gross'];
            $totalDiscounts += $b['discounts'];
            $totalCount += $b['count'];
        }

        usort($rows, fn (RevenueRow $a, RevenueRow $b) => $b->net_revenue <=> $a->net_revenue);

        return new RevenueReportData(
            period_start: $periodStart,
            period_end: $periodEnd,
            currency: $currency,
            group_by: $groupBy,
            rows: new DataCollection(RevenueRow::class, $rows),
            total_gross_revenue: $totalGross,
            total_discounts: $totalDiscounts,
            total_net_revenue: $totalGross - $totalDiscounts,
            total_invoice_count: $totalCount,
        );
    }
}
