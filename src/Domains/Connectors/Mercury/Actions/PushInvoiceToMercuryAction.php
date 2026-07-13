<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Mercury\Actions;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
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
 * Publishes a Scribe invoice to Mercury so the customer gets a pay page and Mercury can collect card/ACH.
 *
 * **Scribe stays the book of record.** Mercury AR is a delivery and collection channel, nothing more. This
 * action posts NO journal entry: the invoice was already booked (DR AR / CR Revenue) when it was issued, and
 * the cash gets booked when the customer's payment lands in the bank feed and the matcher settles it. Pushing
 * a copy of the document to Mercury changes nothing about what we owe or are owed.
 *
 * ## The echo guard
 *
 * We refuse to push an invoice that CAME FROM Mercury (`source='mercury'`). Without that, a pulled invoice
 * would be pushed straight back as a new one, which would then be pulled again — a loop that manufactures a
 * fresh invoice on every cycle and bills the customer repeatedly. Same guard, same reason, as every other
 * bidirectional connector in this codebase.
 *
 * Idempotent on the `MERCURY_INVOICE_ID` custom field: an invoice already on Mercury is not sent twice.
 */
class PushInvoiceToMercuryAction
{
    public function __construct(
        public readonly AppInterface $app,
        public readonly CompanyInterface $company,
        public readonly Invoice $invoice,
        protected readonly ?MercuryInvoiceService $invoiceService = null,
        protected readonly ?MercuryCustomerService $customerService = null,
    ) {
    }

    public function execute(): MercuryInvoice
    {
        $this->assertPushable();

        $customerId = new PushCustomerToMercuryAction(
            app: $this->app,
            company: $this->company,
            organization: $this->customerOrganization(),
            customerService: $this->customerService,
        )->execute();

        $service = $this->invoiceService ?? new MercuryInvoiceService($this->app, $this->company);

        $created = $service->create(
            MercuryInvoice::payloadFromInvoice(
                invoice: $this->invoice,
                mercuryCustomerId: $customerId,
                destinationAccountId: $this->destinationAccountId(),
                sendEmail: $this->shouldSendEmail(),
            ),
        );

        $this->invoice->set(CustomFieldEnum::INVOICE_ID->value, $created->id);

        // external_url is the customer-facing pay page — the single most useful thing Mercury hands back.
        // It's what goes in a dunning email or the answer to "where do I pay?".
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

        // A DRAFT hasn't been booked (no DR AR / CR Revenue) and isn't a real obligation yet. Sending it to a
        // customer would ask them to pay something our own books don't record them owing.
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
        $organization = Organization::getByIdFromCompanyApp($organizationId, $this->company, $this->app);

        return $organization;
    }

    /**
     * Which Mercury account collects the money. Explicit config wins; otherwise the tenant's checking
     * account, which is what a company with a single account expects.
     */
    private function destinationAccountId(): string
    {
        $configured = $this->company->get(ConfigurationEnum::AR_DEPOSIT_ACCOUNT_ID->value);

        if (! empty($configured)) {
            return (string) $configured;
        }

        $checking = BankAccount::query()
            ->where('apps_id', $this->app->getId())
            ->where('companies_id', $this->company->getId())
            ->where('source', 'mercury')
            ->where('is_deleted', false)
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
        return (bool) $this->company->get(ConfigurationEnum::AR_SEND_EMAIL->value);
    }
}
