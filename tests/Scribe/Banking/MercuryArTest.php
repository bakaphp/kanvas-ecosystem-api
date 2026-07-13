<?php

declare(strict_types=1);

namespace Tests\Scribe\Banking;

use Kanvas\Connectors\Mercury\Actions\PullMercuryCustomersAction;
use Kanvas\Connectors\Mercury\Actions\PullMercuryInvoicesAction;
use Kanvas\Connectors\Mercury\Actions\PushCustomerToMercuryAction;
use Kanvas\Connectors\Mercury\Actions\PushInvoiceToMercuryAction;
use Kanvas\Connectors\Mercury\Activities\PushCustomerToMercuryActivity;
use Kanvas\Connectors\Mercury\Activities\PushInvoiceToMercuryActivity;
use Kanvas\Connectors\Mercury\Enums\CustomFieldEnum;
use Kanvas\Connectors\Mercury\Services\MercuryCustomerService;
use Kanvas\Connectors\Mercury\Services\MercuryInvoiceService;
use Kanvas\Guild\Organizations\Models\Organization;
use Kanvas\Scribe\Banking\Actions\CreateBankAccountAction;
use Kanvas\Scribe\Banking\DataTransferObject\BankAccount as BankAccountData;
use Kanvas\Scribe\Invoices\Enums\InvoiceDocumentStatusEnum;
use Kanvas\Scribe\Invoices\Models\Invoice;
use Kanvas\Scribe\Ledger\Enums\AccountSubTypeEnum;
use Kanvas\Scribe\Ledger\Models\JournalEntry;
use RuntimeException;
use Tests\Connectors\Traits\HasMercuryConfiguration;
use Tests\Scribe\ScribeTestCase;

/**
 * Mercury AR (`/ar/invoices`, `/ar/customers`) — publishing Scribe invoices so customers get a pay page.
 *
 * The rule this file exists to pin: **Scribe is the book of record; Mercury AR is a delivery and collection
 * channel.** Pushing a copy of a document to Mercury changes nothing about what we're owed, and Mercury
 * saying "Paid" is not the same as money being in the account. Only the bank feed settles anything.
 */
final class MercuryArTest extends ScribeTestCase
{
    use HasMercuryConfiguration;

    private const string MERCURY_ACCOUNT_ID = 'acct-11111111-2222-3333-4444-555555555555';

    protected function afterScribeSetUp(): void
    {
        $this->configureMercury($this->company);

        new CreateBankAccountAction(
            data: new BankAccountData(
                app: $this->kanvasApp,
                company: $this->company,
                account_name: 'Test Checking',
                gl_account_id: $this->accountIdBySubType(AccountSubTypeEnum::CASH_CHECKING),
                currency: 'USD',
                source: 'mercury',
                external_id: self::MERCURY_ACCOUNT_ID,
            ),
            user: static::$cachedUser,
        )->execute();
    }

    public function testPushingAnInvoiceCreatesTheCustomerAndStoresThePayPageUrl(): void
    {
        $customer = $this->customerWithEmail('Initech LLC', 'ap@initech.test');
        $invoice = $this->issueTestInvoice($customer, 5_000.00);

        $before = $this->journalEntryCount();

        $pushed = new PushInvoiceToMercuryAction(
            invoice: $invoice,
            invoiceService: $this->invoiceServiceReturning([
                'id' => 'minv-1',
                'invoiceNumber' => $invoice->invoice_number,
                'status' => 'Unpaid',
                'amount' => 5_000.00,
                'slug' => 'abc123',
                'customerId' => 'mcus-1',
            ]),
            customerService: $this->customerServiceReturning([
                'id' => 'mcus-1',
                'name' => 'Initech LLC',
                'email' => 'ap@initech.test',
            ]),
        )->execute();

        $this->assertSame('minv-1', $pushed->id);
        $this->assertSame('https://mercury.com/pay/abc123', $pushed->payPageUrl());

        $invoice->refresh();
        $this->assertSame('minv-1', (string) $invoice->get(CustomFieldEnum::INVOICE_ID->value));
        $this->assertSame('https://mercury.com/pay/abc123', $invoice->external_url);

        // The customer was created on Mercury and the mapping remembered, so the next invoice reuses it
        // rather than creating a duplicate customer whose AR ageing splits in two.
        $this->assertSame('mcus-1', (string) $customer->refresh()->get(CustomFieldEnum::CUSTOMER_ID->value));

        // Publishing a copy of the document changes nothing about what we're owed. The AR entry was posted
        // when the invoice was ISSUED; this posts nothing.
        $this->assertSame($before, $this->journalEntryCount(), 'Pushing to Mercury must post no JE.');
    }

    public function testAnInvoiceThatCameFromMercuryIsNeverPushedBack(): void
    {
        $customer = $this->customerWithEmail('Initech LLC', 'ap@initech.test');
        $invoice = $this->issueTestInvoice($customer, 1_000.00);
        $invoice->source = 'mercury';
        $invoice->saveQuietly();

        // Without this guard, a pulled invoice gets pushed back as a NEW invoice, which is then pulled
        // again — a loop that bills the customer afresh on every cycle.
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/originated in Mercury/');

        new PushInvoiceToMercuryAction(
            invoice: $invoice,
            invoiceService: $this->invoiceServiceReturning(['id' => 'minv-x', 'status' => 'Unpaid', 'amount' => 1_000.00]),
        )->execute();
    }

    public function testAnInvoiceIsNotPushedTwice(): void
    {
        $customer = $this->customerWithEmail('Initech LLC', 'ap@initech.test');
        $invoice = $this->issueTestInvoice($customer, 1_000.00);
        $invoice->set(CustomFieldEnum::INVOICE_ID->value, 'minv-existing');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/already on Mercury/');

        new PushInvoiceToMercuryAction(
            invoice: $invoice->refresh(),
            invoiceService: $this->invoiceServiceReturning(['id' => 'minv-y', 'status' => 'Unpaid', 'amount' => 1_000.00]),
        )->execute();
    }

    public function testADraftInvoiceCannotBePublishedToACustomer(): void
    {
        $customer = $this->customerWithEmail('Initech LLC', 'ap@initech.test');

        // A draft isn't booked (no DR AR / CR Revenue) and isn't an obligation yet. Sending it would ask a
        // customer to pay something our own books don't record them owing.
        $invoice = $this->issueTestInvoice($customer, 500.00);
        $invoice->document_status = InvoiceDocumentStatusEnum::DRAFT;
        $invoice->saveQuietly();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/not yet an obligation/');

        new PushInvoiceToMercuryAction(
            invoice: $invoice,
            invoiceService: $this->invoiceServiceReturning(['id' => 'minv-z', 'status' => 'Unpaid', 'amount' => 500.00]),
        )->execute();
    }

    public function testACustomerWithNoEmailIsRefusedWithAUsefulReason(): void
    {
        // Mercury delivers the invoice BY email. A customer with none produces an invoice nobody receives.
        $customer = $this->seedTestOrganization('No Email Corp');

        $this->expectExceptionMessageMatches('/has no email/');

        new PushCustomerToMercuryAction(
            organization: $customer,
            customerService: $this->customerServiceReturning(['id' => 'mcus-9', 'name' => 'No Email Corp', 'email' => '']),
        )->execute();
    }

    public function testTheInvoiceActivityRefusesADraft(): void
    {
        $customer = $this->customerWithEmail('Initech LLC', 'ap@initech.test');
        $invoice = $this->issueTestInvoice($customer, 500.00);
        $invoice->document_status = InvoiceDocumentStatusEnum::DRAFT;
        $invoice->saveQuietly();

        // The activity is wired to the invoice's status-transition event, and a draft has no posted JE — so
        // sending it would ask a customer to pay something our own books don't record them owing.
        $outcome = $this->activity(PushInvoiceToMercuryActivity::class)->execute($invoice->refresh(), $this->kanvasApp, []);

        $this->assertSame('skipped', $outcome['status']);
        $this->assertSame('not_issued', $outcome['reason']);
    }

    public function testTheInvoiceActivityRefusesToEchoBackAnInvoiceThatCameFromMercury(): void
    {
        $customer = $this->customerWithEmail('Initech LLC', 'ap@initech.test');
        $invoice = $this->issueTestInvoice($customer, 500.00);
        $invoice->source = 'mercury';
        $invoice->saveQuietly();

        // Without this guard the pulled invoice is pushed back as a NEW one, which is then pulled again — a
        // loop that bills the customer afresh on every cycle.
        $outcome = $this->activity(PushInvoiceToMercuryActivity::class)->execute($invoice->refresh(), $this->kanvasApp, []);

        $this->assertSame('skipped', $outcome['status']);
        $this->assertSame('originated_in_mercury', $outcome['reason']);
    }

    public function testTheCustomerActivityRefusesAnOrganizationWithNoEmail(): void
    {
        // Mercury delivers invoices BY email. An org without one isn't an error — it just isn't a billing
        // customer yet, and creating it would produce an invoice nobody ever receives.
        $outcome = $this->activity(PushCustomerToMercuryActivity::class)->execute(
            $this->seedTestOrganization('No Email Corp'),
            $this->kanvasApp,
            [],
        );

        $this->assertSame('skipped', $outcome['status']);
        $this->assertSame('no_email', $outcome['reason']);
    }

    public function testTheCustomerActivityDoesNotPushTwice(): void
    {
        $customer = $this->customerWithEmail('Initech LLC', 'ap@initech.test');
        $customer->set(CustomFieldEnum::CUSTOMER_ID->value, 'mcus-1');

        // Organizations are updated constantly, and the activity fires on every update. Mercury will happily
        // hold five customers all called "Initech LLC" and split their AR ageing across all five.
        $outcome = $this->activity(PushCustomerToMercuryActivity::class)->execute($customer->refresh(), $this->kanvasApp, []);

        $this->assertSame('skipped', $outcome['status']);
        $this->assertSame('already_in_mercury', $outcome['reason']);
    }

    public function testPullingStatusForAnInvoiceWePushedDoesNotSettleIt(): void
    {
        $customer = $this->customerWithEmail('Initech LLC', 'ap@initech.test');
        $invoice = $this->issueTestInvoice($customer, 5_000.00);
        $invoice->external_id = 'minv-1';
        $invoice->saveQuietly();

        $before = $this->journalEntryCount();

        $result = new PullMercuryInvoicesAction(
            app: $this->kanvasApp,
            company: $this->company,
            user: static::$cachedUser,
            invoiceService: $this->invoiceServiceListing([[
                'id' => 'minv-1',
                'invoiceNumber' => $invoice->invoice_number,
                'status' => 'Paid',
                'amount' => 5_000.00,
                'slug' => 'abc123',
            ]]),
        )->execute();

        $this->assertSame(1, $result['synced']);
        $this->assertSame(0, $result['imported']);

        $invoice->refresh();
        $this->assertSame('Paid', $invoice->metadata['mercury_status']);

        // THE rule. Mercury saying "Paid" means it COLLECTED — the money may still be in transit. The invoice
        // is settled when the deposit actually lands and the bank matcher clears it. Marking it paid here
        // would credit AR against cash that hasn't arrived.
        $this->assertSame(
            InvoiceDocumentStatusEnum::ISSUED,
            $invoice->document_status,
            'Mercury status is visibility only — the bank feed settles invoices, not the AR API.'
        );
        $this->assertSame($before, $this->journalEntryCount(), 'Status sync must post no JE.');
    }

    public function testAnInvoiceCreatedInMercuryIsMirroredOntoOurBooksAndIssued(): void
    {
        $customer = $this->customerWithEmail('Initech LLC', 'ap@initech.test');
        $customer->set(CustomFieldEnum::CUSTOMER_ID->value, 'mcus-1');

        $arBefore = $this->netMovementOn(AccountSubTypeEnum::ACCOUNTS_RECEIVABLE);

        $result = new PullMercuryInvoicesAction(
            app: $this->kanvasApp,
            company: $this->company,
            user: static::$cachedUser,
            invoiceService: $this->invoiceServiceListing([[
                'id' => 'minv-native',
                'invoiceNumber' => 'MERC-001',
                'status' => 'Unpaid',
                'amount' => 2_500.00,
                'customerId' => 'mcus-1',
                'invoiceDate' => '2026-06-10',
                'dueDate' => '2026-07-10',
                'lineItems' => [
                    ['name' => 'Consulting', 'unitPrice' => 2_500.00, 'quantity' => 1, 'salesTaxRate' => null],
                ],
            ]]),
        )->execute();

        $this->assertSame(1, $result['imported']);

        $imported = Invoice::query()->where('external_id', 'minv-native')->firstOrFail();
        $this->assertSame('mercury', $imported->source);
        $this->assertSame(InvoiceDocumentStatusEnum::ISSUED, $imported->document_status);
        $this->assertSame(2_500.00, round($imported->total_native, 2));

        // Someone deliberately invoiced a real customer, so the receivable and the revenue are real. Unlike
        // Acumatica — where the ERP owns the GL and imports post nothing — Kanvas owns the GL here.
        $this->assertSame(
            $arBefore + 2_500.00,
            $this->netMovementOn(AccountSubTypeEnum::ACCOUNTS_RECEIVABLE)
        );
    }

    public function testACancelledMercuryInvoiceIsNotImported(): void
    {
        $customer = $this->customerWithEmail('Initech LLC', 'ap@initech.test');
        $customer->set(CustomFieldEnum::CUSTOMER_ID->value, 'mcus-1');

        $result = new PullMercuryInvoicesAction(
            app: $this->kanvasApp,
            company: $this->company,
            user: static::$cachedUser,
            invoiceService: $this->invoiceServiceListing([[
                'id' => 'minv-cancelled',
                'status' => 'Cancelled',
                'amount' => 900.00,
                'customerId' => 'mcus-1',
            ]]),
        )->execute();

        // A cancelled invoice is not a receivable. Booking revenue for it would be inventing income.
        $this->assertSame(0, $result['imported']);
        $this->assertSame(1, $result['skipped']);
        $this->assertSame(0, Invoice::query()->where('external_id', 'minv-cancelled')->count());
    }

    public function testPullingCustomersLinksAnExistingOrganizationByEmailInsteadOfDuplicatingIt(): void
    {
        $existing = $this->customerWithEmail('Initech LLC', 'ap@initech.test');

        $organizations = new PullMercuryCustomersAction(
            app: $this->kanvasApp,
            company: $this->company,
            user: static::$cachedUser,
            customerService: $this->customerServiceListing([
                ['id' => 'mcus-1', 'name' => 'Initech Limited', 'email' => 'ap@initech.test'],
            ]),
        )->execute();

        $this->assertCount(1, $organizations);
        $this->assertSame($existing->getId(), $organizations[0]->getId(), 'Matched on email, not duplicated.');
        $this->assertSame('mcus-1', (string) $existing->refresh()->get(CustomFieldEnum::CUSTOMER_ID->value));
    }

    private function customerWithEmail(string $name, string $email): Organization
    {
        $organization = $this->seedTestOrganization($name);
        $organization->email = $email;
        $organization->saveQuietly();

        return $organization;
    }

    private function invoiceServiceReturning(array $created): MercuryInvoiceService
    {
        return new MercuryInvoiceService(
            $this->kanvasApp,
            $this->company,
            $this->mercuryClientReturning($this->kanvasApp, $this->company, [$created]),
        );
    }

    private function invoiceServiceListing(array $invoices): MercuryInvoiceService
    {
        return new MercuryInvoiceService(
            $this->kanvasApp,
            $this->company,
            $this->mercuryClientReturning($this->kanvasApp, $this->company, [['invoices' => $invoices]]),
        );
    }

    private function customerServiceReturning(array $created): MercuryCustomerService
    {
        return new MercuryCustomerService(
            $this->kanvasApp,
            $this->company,
            $this->mercuryClientReturning($this->kanvasApp, $this->company, [$created]),
        );
    }

    private function customerServiceListing(array $customers): MercuryCustomerService
    {
        return new MercuryCustomerService(
            $this->kanvasApp,
            $this->company,
            $this->mercuryClientReturning($this->kanvasApp, $this->company, [['customers' => $customers]]),
        );
    }

    private function journalEntryCount(): int
    {
        return JournalEntry::query()
            ->where('apps_id', $this->kanvasApp->getId())
            ->where('companies_id', $this->company->getId())
            ->count();
    }

    private function netMovementOn(AccountSubTypeEnum $subType): float
    {
        $accountId = $this->accountIdBySubType($subType);

        $lines = JournalEntry::query()
            ->where('apps_id', $this->kanvasApp->getId())
            ->where('companies_id', $this->company->getId())
            ->with('lines')
            ->get()
            ->flatMap(fn (JournalEntry $entry) => $entry->lines)
            ->where('account_id', $accountId);

        return round((float) $lines->sum('debit_base') - (float) $lines->sum('credit_base'), 2);
    }
}
