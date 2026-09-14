<?php

declare(strict_types=1);

namespace App\GraphQL\Scribe\Mutations\Invoices;

use Illuminate\Support\Carbon;
use Kanvas\Apps\Models\Apps;
use Kanvas\Filesystem\Models\Filesystem;
use Kanvas\Guild\Organizations\Models\Organization;
use Kanvas\Scribe\Documents\Services\DocumentPdfService;
use Kanvas\Scribe\Invoices\Actions\AllocateInvoicePaymentAction;
use Kanvas\Scribe\Invoices\Actions\AmendInvoiceAction;
use Kanvas\Scribe\Invoices\Actions\CreateInvoiceAction;
use Kanvas\Scribe\Invoices\Actions\IssueCreditNoteAction;
use Kanvas\Scribe\Invoices\Actions\IssueInvoiceAction;
use Kanvas\Scribe\Invoices\Actions\MarkInvoicePaidAction;
use Kanvas\Scribe\Invoices\Actions\UpdateInvoiceAction;
use Kanvas\Scribe\Invoices\Actions\VoidInvoiceAction;
use Kanvas\Scribe\Invoices\DataTransferObject\AmendInvoice as AmendInvoiceData;
use Kanvas\Scribe\Invoices\DataTransferObject\Invoice as InvoiceData;
use Kanvas\Scribe\Invoices\Models\Invoice;
use Kanvas\Scribe\Invoices\Models\InvoicePaymentAllocation;
use Kanvas\Scribe\Ledger\Enums\AccountSubTypeEnum;
use Kanvas\Scribe\Payments\Enums\PaymentMethodEnum;
use RuntimeException;

class InvoiceMutation
{
    public function create(mixed $rootValue, array $request): Invoice
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        return new CreateInvoiceAction(
            data: InvoiceData::from($app, $company, $request['input']),
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

        if ($invoice->customer_organization_id === null) {
            throw new RuntimeException(
                "Invoice {$invoice->id} has no customer reference set — assign one before issuing."
            );
        }

        /** @var Organization $billable */
        $billable = Organization::getByIdFromCompanyApp(
            (int) $invoice->customer_organization_id,
            $company,
            $app,
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

    public function allocatePayment(mixed $rootValue, array $request): InvoicePaymentAllocation
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        $input = $request['input'];

        /** @var Invoice $invoice */
        $invoice = Invoice::getByIdFromCompanyApp((int) $request['id'], $company, $app);

        return new AllocateInvoicePaymentAction(
            invoice: $invoice,
            amountNative: (float) $input['amount_native'],
            method: isset($input['method']) ? PaymentMethodEnum::from((string) $input['method']) : PaymentMethodEnum::MANUAL,
            cashAccountSubType: isset($input['cash_account_sub_type'])
                ? AccountSubTypeEnum::from((string) $input['cash_account_sub_type'])
                : AccountSubTypeEnum::CASH_CHECKING,
            bankAccountId: isset($input['bank_account_id']) ? (int) $input['bank_account_id'] : null,
            reference: $input['reference'] ?? null,
            user: $user,
            source: $input['source'] ?? 'kanvas',
            metadata: $input['metadata'] ?? null,
            paidAt: isset($input['paid_at']) ? Carbon::parse((string) $input['paid_at']) : null,
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
            data: InvoiceData::from($app, $company, $request['input']),
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
            data: InvoiceData::from($app, $company, $request['input']),
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

    public function generatePdf(mixed $rootValue, array $request): Filesystem
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        /** @var Invoice $invoice */
        $invoice = Invoice::getByIdFromCompanyApp((int) $request['id'], $company, $app);

        return new DocumentPdfService($invoice, $request['template_name'] ?? null)->generate($user);
    }
}
