<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Mercury\Actions;

use Kanvas\Connectors\Mercury\DataTransferObject\MercuryInvoice;
use Kanvas\Connectors\Mercury\Enums\ConfigurationEnum;
use Kanvas\Connectors\Mercury\Enums\CustomFieldEnum;
use Kanvas\Connectors\Mercury\Services\MercuryCustomerService;
use Kanvas\Connectors\Mercury\Services\MercuryInvoiceService;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Guild\Organizations\Models\Organization;
use Kanvas\Scribe\Banking\Models\BankAccount;
use Kanvas\Scribe\Invoices\Enums\InvoiceDocumentStatusEnum;
use Kanvas\Scribe\Invoices\Models\Invoice;
use Kanvas\Scribe\Ledger\Enums\AccountSubTypeEnum;
use RuntimeException;

/**
 * Scribe stays the book of record; Mercury AR is a delivery and collection channel. Posts NO journal entry —
 * the invoice was booked at issue, and the cash is booked when the bank feed lands and the matcher settles it.
 *
 * Idempotent on `MERCURY_INVOICE_ID`, and refuses to push an invoice that CAME FROM Mercury: pushing a pulled
 * invoice back mints a new one, which is then pulled again, billing the customer afresh every cycle.
 */
class PushInvoiceToMercuryAction
{
    public function __construct(
        public readonly Invoice $invoice,
        protected readonly ?MercuryInvoiceService $invoiceService = null,
        protected readonly ?MercuryCustomerService $customerService = null,
    ) {
    }

    public function execute(): MercuryInvoice
    {
        $this->assertPushable();

        $customerId = new PushCustomerToMercuryAction(
            organization: $this->customerOrganization(),
            customerService: $this->customerService,
        )->execute();

        $service = $this->invoiceService ?? new MercuryInvoiceService(
            $this->invoice->app,
            $this->invoice->company,
        );

        $created = $service->create(
            MercuryInvoice::payloadFromInvoice(
                invoice: $this->invoice,
                mercuryCustomerId: $customerId,
                destinationAccountId: $this->destinationAccountId(),
                sendEmail: $this->shouldSendEmail(),
            ),
        );

        $this->invoice->set(CustomFieldEnum::INVOICE_ID->value, $created->id);

        $this->invoice->external_url = $created->payPageUrl();
        $this->invoice->saveQuietly();

        $this->invoice->emitLedgerEvent('accounting.invoice.pushed_to_mercury', payload: [
            'mercury_invoice_id' => $created->id,
            'pay_page_url' => $created->payPageUrl(),
            'emailed' => $this->shouldSendEmail(),
        ]);

        return $created;
    }

    private function assertPushable(): void
    {
        if ($this->invoice->source === 'mercury') {
            throw new RuntimeException(
                "Invoice {$this->invoice->getId()} originated in Mercury — pushing it back would create a "
                . 'duplicate and bill the customer twice.'
            );
        }

        $existing = $this->invoice->get(CustomFieldEnum::INVOICE_ID->value);
        if (! empty($existing)) {
            throw new RuntimeException(
                "Invoice {$this->invoice->getId()} is already on Mercury as {$existing}."
            );
        }

        // A draft has no posted JE — sending it asks a customer to pay something our books don't record.
        if (! in_array($this->invoice->document_status, [
            InvoiceDocumentStatusEnum::ISSUED,
            InvoiceDocumentStatusEnum::SENT,
        ], true)) {
            throw new RuntimeException(
                "Invoice {$this->invoice->getId()} is {$this->invoice->document_status->value}. Issue it "
                . 'before publishing it to Mercury — a draft is not yet an obligation.'
            );
        }
    }

    private function customerOrganization(): Organization
    {
        $organizationId = $this->invoice->customer_organization_id;

        if ($organizationId === null) {
            throw new ValidationException(
                "Invoice {$this->invoice->getId()} has no customer organization. Mercury bills a customer, "
                . 'so there is nobody to send this to.'
            );
        }

        /** @var Organization $organization */
        $organization = Organization::getByIdFromCompanyApp(
            $organizationId,
            $this->invoice->company,
            $this->invoice->app,
        );

        return $organization;
    }

    private function destinationAccountId(): string
    {
        $configured = $this->invoice->company->get(ConfigurationEnum::AR_DEPOSIT_ACCOUNT_ID->value);

        if (! empty($configured)) {
            return (string) $configured;
        }

        $checking = BankAccount::query()
            ->fromApp($this->invoice->app)
            ->fromCompany($this->invoice->company)
            ->notDeleted()
            ->where('source', 'mercury')
            ->whereHas(
                'glAccount',
                fn ($query) => $query->where('account_sub_type', AccountSubTypeEnum::CASH_CHECKING->value),
            )
            ->first();

        $externalId = $checking?->external_id;

        if ($externalId === null) {
            throw new ValidationException(
                'No Mercury account to deposit into. Pull the Mercury accounts first, or set '
                . ConfigurationEnum::AR_DEPOSIT_ACCOUNT_ID->value . ' to the account that should collect AR.'
            );
        }

        return $externalId;
    }

    private function shouldSendEmail(): bool
    {
        return (bool) $this->invoice->company->get(ConfigurationEnum::AR_SEND_EMAIL->value);
    }
}
