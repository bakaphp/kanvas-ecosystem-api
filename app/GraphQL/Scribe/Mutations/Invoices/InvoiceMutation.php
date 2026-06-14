<?php

declare(strict_types=1);

namespace App\GraphQL\Scribe\Mutations\Invoices;

use App\GraphQL\Scribe\Resolvers\BillableResolver;
use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Illuminate\Support\Carbon;
use Kanvas\Apps\Models\Apps;
use Kanvas\Scribe\Invoices\Actions\AmendInvoiceAction;
use Kanvas\Scribe\Invoices\Actions\CreateInvoiceAction;
use Kanvas\Scribe\Invoices\Actions\IssueCreditNoteAction;
use Kanvas\Scribe\Invoices\Actions\IssueInvoiceAction;
use Kanvas\Scribe\Invoices\Actions\MarkInvoicePaidAction;
use Kanvas\Scribe\Invoices\Actions\UpdateInvoiceAction;
use Kanvas\Scribe\Invoices\Actions\VoidInvoiceAction;
use Kanvas\Scribe\Invoices\DataTransferObject\AmendInvoiceData;
use Kanvas\Scribe\Invoices\DataTransferObject\InvoiceData;
use Kanvas\Scribe\Invoices\DataTransferObject\InvoiceLineData;
use Kanvas\Scribe\Invoices\Enums\DocumentTypeEnum;
use Kanvas\Scribe\Invoices\Models\Invoice;
use Spatie\LaravelData\DataCollection;

class InvoiceMutation
{
    public function __construct(
        protected readonly BillableResolver $billableResolver = new BillableResolver(),
    ) {
    }

    public function create(mixed $rootValue, array $request): Invoice
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        return new CreateInvoiceAction(
            data: $this->buildInvoiceData($request['input'], $app, $company),
            user: $user,
        )->execute();
    }

    public function issue(mixed $rootValue, array $request): Invoice
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        /** @var Invoice $invoice */
        $invoice = Invoice::getByIdFromCompanyApp((int) $request['id'], $company, $app);

        if ($invoice->billable_type === null || $invoice->billable_id === null) {
            throw new \RuntimeException(
                "Invoice {$invoice->id} has no billable reference set — assign one before issuing."
            );
        }

        $billable = $this->billableResolver->resolveBillable(
            $invoice->billable_type,
            (int) $invoice->billable_id,
            $app,
            $company,
        );

        return new IssueInvoiceAction(
            invoice: $invoice,
            billable: $billable,
            user: $user,
        )->execute();
    }

    public function void(mixed $rootValue, array $request): Invoice
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        /** @var Invoice $invoice */
        $invoice = Invoice::getByIdFromCompanyApp((int) $request['id'], $company, $app);

        return new VoidInvoiceAction(
            invoice: $invoice,
            voidReasonCode: (string) $request['void_reason_code'],
            user: $user,
        )->execute();
    }

    public function markPaid(mixed $rootValue, array $request): Invoice
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        /** @var Invoice $invoice */
        $invoice = Invoice::getByIdFromCompanyApp((int) $request['id'], $company, $app);

        return new MarkInvoicePaidAction(
            invoice: $invoice,
            user: $user,
        )->execute();
    }

    public function update(mixed $rootValue, array $request): Invoice
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        /** @var Invoice $invoice */
        $invoice = Invoice::getByIdFromCompanyApp((int) $request['id'], $company, $app);

        return new UpdateInvoiceAction(
            invoice: $invoice,
            data: $this->buildInvoiceData($request['input'], $app, $company),
            user: $user,
        )->execute();
    }

    public function issueCreditNote(mixed $rootValue, array $request): Invoice
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        /** @var Invoice $parent */
        $parent = Invoice::getByIdFromCompanyApp((int) $request['parent_invoice_id'], $company, $app);

        return new IssueCreditNoteAction(
            parentInvoice: $parent,
            data: $this->buildInvoiceData($request['input'], $app, $company),
            user: $user,
        )->execute();
    }

    public function amend(mixed $rootValue, array $request): Invoice
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        $input = $request['input'];

        /** @var Invoice $invoice */
        $invoice = Invoice::getByIdFromCompanyApp((int) $request['id'], $company, $app);

        return new AmendInvoiceAction(
            invoice: $invoice,
            data: new AmendInvoiceData(
                reason: (string) $input['reason'],
                due_date: isset($input['due_date']) ? Carbon::parse((string) $input['due_date']) : null,
                expected_payment_date: isset($input['expected_payment_date'])
                    ? Carbon::parse((string) $input['expected_payment_date'])
                    : null,
                net_terms_days: isset($input['net_terms_days']) ? (int) $input['net_terms_days'] : null,
                notes: $input['notes'] ?? null,
                internal_notes: $input['internal_notes'] ?? null,
                terms: $input['terms'] ?? null,
                regional_compliance: $input['regional_compliance'] ?? null,
                external_id: $input['external_id'] ?? null,
                external_url: $input['external_url'] ?? null,
                metadata: $input['metadata'] ?? null,
            ),
            user: $user,
        )->execute();
    }

    private function buildInvoiceData(
        array $input,
        AppInterface $app,
        CompanyInterface $company,
    ): InvoiceData {
        $billable = $this->billableResolver->resolveBillableOrNull(
            $input['billable_type'] ?? null,
            isset($input['billable_id']) ? (int) $input['billable_id'] : null,
            $app,
            $company,
        );

        $lines = new DataCollection(InvoiceLineData::class, array_map(
            fn (array $line): InvoiceLineData => new InvoiceLineData(
                description: (string) ($line['description'] ?? ''),
                quantity: (float) ($line['quantity'] ?? 1),
                unit_price_native: (float) $line['unit_price_native'],
                item_id: isset($line['item_id']) ? (int) $line['item_id'] : null,
                sort_order: isset($line['sort_order']) ? (int) $line['sort_order'] : null,
                discount_rate: isset($line['discount_rate']) ? (float) $line['discount_rate'] : null,
                discount_amount_native: (float) ($line['discount_amount_native'] ?? 0),
                tax_rate: isset($line['tax_rate']) ? (float) $line['tax_rate'] : null,
                tax_amount_native: (float) ($line['tax_amount_native'] ?? 0),
                class_id: isset($line['class_id']) ? (int) $line['class_id'] : null,
                department_id: isset($line['department_id']) ? (int) $line['department_id'] : null,
                metadata: $line['metadata'] ?? null,
            ),
            $input['lines'],
        ));

        return new InvoiceData(
            app: $app,
            company: $company,
            billable: $billable,
            lines: $lines,
            currency: (string) $input['currency'],
            fx_rate_to_base: (float) ($input['fx_rate_to_base'] ?? 1.0),
            document_type: isset($input['document_type'])
                ? DocumentTypeEnum::from((string) $input['document_type'])
                : DocumentTypeEnum::INVOICE,
            invoice_number: $input['invoice_number'] ?? null,
            net_terms_days: isset($input['net_terms_days']) ? (int) $input['net_terms_days'] : null,
            issued_date: isset($input['issued_date']) ? Carbon::parse((string) $input['issued_date']) : null,
            due_date: isset($input['due_date']) ? Carbon::parse((string) $input['due_date']) : null,
            expected_payment_date: isset($input['expected_payment_date'])
                ? Carbon::parse((string) $input['expected_payment_date'])
                : null,
            notes: $input['notes'] ?? null,
            internal_notes: $input['internal_notes'] ?? null,
            terms: $input['terms'] ?? null,
            quote_id: isset($input['quote_id']) ? (int) $input['quote_id'] : null,
            parent_invoice_id: isset($input['parent_invoice_id']) ? (int) $input['parent_invoice_id'] : null,
            regional_compliance: $input['regional_compliance'] ?? null,
            tax_metadata: $input['tax_metadata'] ?? null,
            metadata: $input['metadata'] ?? null,
        );
    }
}
