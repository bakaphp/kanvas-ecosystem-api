<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Invoices\Services;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Kanvas\Guild\Organizations\Models\Organization;
use Kanvas\Guild\Organizations\Services\OrganizationNameNormalizerService;
use Kanvas\Scribe\Invoices\Models\Invoice;

/**
 * A customer's open invoices against a payment they announced: by customer name, plus any invoice numbers
 * the remittance names, flagging the one invoice whose balance equals the amount. Proposes only; the
 * payment is applied when the bank confirms it.
 */
class OpenInvoiceMatcherService
{
    /**
     * @param list<string> $invoiceNumbers
     * @return array{customer: string, amount: ?float, customer_found: bool, exact_match: ?string, total_open: float, open_invoices: list<array<string, mixed>>, referenced_invoices: list<array<string, mixed>>}
     */
    public function match(
        AppInterface $app,
        CompanyInterface $company,
        string $customer,
        ?float $amount,
        array $invoiceNumbers = [],
        int $limit = 25,
    ): array {
        $orgIds = $this->customerIds($app, $company, $customer);
        $invoices = $orgIds->isEmpty() ? collect() : $this->openInvoices($app, $company)
            ->whereIn('customer_organization_id', $orgIds)
            ->orderBy('due_date')
            ->limit($limit)
            ->get();

        $referenced = $invoiceNumbers === [] ? collect() : $this->openInvoices($app, $company)
            ->whereIn('invoice_number', $invoiceNumbers)
            ->get();

        $exact = $amount === null ? null : $invoices->merge($referenced)
            ->first(fn (Invoice $invoice): bool => abs((float) $invoice->balance_due_native - $amount) < 0.005);

        return [
            'customer' => $customer,
            'amount' => $amount,
            'customer_found' => $orgIds->isNotEmpty(),
            'exact_match' => $exact?->invoice_number,
            'total_open' => round((float) $invoices->sum('balance_due_native'), 2),
            'open_invoices' => $this->present($invoices),
            'referenced_invoices' => $this->present($referenced),
        ];
    }

    /**
     * @return Collection<int, int>
     */
    private function customerIds(AppInterface $app, CompanyInterface $company, string $customer): Collection
    {
        $term = OrganizationNameNormalizerService::normalize($customer) ?: $customer;
        if (trim($term) === '') {
            return collect();
        }

        return Organization::query()
            ->where('apps_id', $app->getId())
            ->where('companies_id', $company->getId())
            ->notDeleted()
            ->where('name', 'like', '%' . $term . '%')
            ->pluck('id');
    }

    private function openInvoices(AppInterface $app, CompanyInterface $company): Builder
    {
        return Invoice::query()
            ->where('apps_id', $app->getId())
            ->where('companies_id', $company->getId())
            ->notDeleted()
            ->open();
    }

    /**
     * @param Collection<int, Invoice> $invoices
     * @return list<array<string, mixed>>
     */
    private function present(Collection $invoices): array
    {
        return $invoices->map(fn (Invoice $invoice): array => [
            'invoice_id' => $invoice->getId(),
            'invoice_number' => $invoice->invoice_number,
            'balance_due' => round((float) $invoice->balance_due_native, 2),
            'due_date' => $invoice->due_date?->toDateString(),
        ])->values()->all();
    }
}
