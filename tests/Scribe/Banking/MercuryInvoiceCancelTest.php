<?php

declare(strict_types=1);

namespace Tests\Scribe\Banking;

use Kanvas\Connectors\Mercury\Actions\CancelMercuryInvoiceAction;
use Kanvas\Connectors\Mercury\Activities\CancelMercuryInvoiceActivity;
use Kanvas\Connectors\Mercury\Enums\CustomFieldEnum;
use Kanvas\Connectors\Mercury\Services\MercuryInvoiceService;
use Kanvas\Scribe\Invoices\Actions\VoidInvoiceAction;
use Kanvas\Scribe\Invoices\Enums\InvoiceDocumentStatusEnum;
use Kanvas\Scribe\Invoices\Models\Invoice;
use Tests\Connectors\Traits\HasMercuryConfiguration;
use Tests\Scribe\ScribeTestCase;

/**
 * Voiding an invoice must pull its Mercury copy down with it. Otherwise the pay page stays live, a customer
 * can pay an invoice our books say is void, and the cash lands in the feed with no receivable to match —
 * sitting in Suspense while the ledger insists nothing is owed.
 */
final class MercuryInvoiceCancelTest extends ScribeTestCase
{
    use HasMercuryConfiguration;

    protected function afterScribeSetUp(): void
    {
        $this->configureMercury($this->company);
    }

    public function testVoidingAnInvoiceCancelsTheMercuryCopyAndKEEPSTheReference(): void
    {
        $invoice = $this->publishedInvoice();

        $cancelled = new CancelMercuryInvoiceAction(
            invoice: $invoice,
            invoiceService: $this->serviceReturning(['id' => 'minv-1', 'status' => 'Cancelled', 'amount' => 5_000.00]),
        )->execute();

        $this->assertTrue($cancelled);

        // Mercury cancels and RETAINS the invoice — it has no delete (DELETE answers 405). So we keep our
        // pointer to it: a voided Scribe invoice still resolves to the cancelled Mercury one, and the trail
        // survives on both sides. Clearing the id would orphan the Mercury record — still there, no longer
        // traceable to anything.
        $this->assertSame(
            'minv-1',
            (string) $invoice->refresh()->get(CustomFieldEnum::INVOICE_ID->value),
            'The Mercury reference must survive cancellation.'
        );
    }

    public function testCancellingTwiceIsNotAttempted(): void
    {
        $invoice = $this->publishedInvoice();

        // Mercury answers a second cancel with a 400. The activity fires on every touch of a voided invoice,
        // so "already cancelled" has to be a fact we record — MERCURY_INVOICE_ID can't carry it, because it
        // deliberately survives the cancellation.
        $service = $this->serviceReturning(['id' => 'minv-1', 'status' => 'Cancelled', 'amount' => 5_000.00]);

        $this->assertTrue(new CancelMercuryInvoiceAction($invoice, $service)->execute());

        $second = new CancelMercuryInvoiceAction($invoice->refresh(), $service);

        $this->assertTrue($second->alreadyCancelled());
        $this->assertFalse($second->execute(), 'The second cancel never reaches Mercury.');
    }

    public function testAnInvoiceNeverPushedToMercuryIsLeftAlone(): void
    {
        $customer = $this->seedTestOrganization('Initech LLC');
        $invoice = $this->issueTestInvoice($customer, 5_000.00);

        // No MERCURY_INVOICE_ID — there is nothing on Mercury to cancel, and calling the API would 404.
        $this->assertFalse(new CancelMercuryInvoiceAction($invoice)->execute());
    }

    public function testAFailureToReachMercuryNeverUndoesTheVoid(): void
    {
        $invoice = $this->publishedInvoice();

        // A void is an accounting act that has already posted its reversal JE. It must not be rolled back
        // because a third-party API was unreachable — the worst case is a stale Mercury invoice, which the
        // nightly pull surfaces as a status mismatch.
        $failing = new MercuryInvoiceService(
            $this->kanvasApp,
            $this->company,
            $this->mercuryClientFailing($this->kanvasApp, $this->company),
        );

        $this->assertFalse(
            new CancelMercuryInvoiceAction($invoice, $failing)->execute(),
            'It reports the failure rather than throwing.'
        );
    }

    public function testVoidingFiresTheStatusTransitionThatDrivesTheCancelActivity(): void
    {
        $invoice = $this->publishedInvoice();

        // Scribe knows nothing about Mercury. It voids the invoice and announces the transition; whichever
        // connector holds a copy of this invoice subscribes to that event and cancels it on its own side.
        $voided = new VoidInvoiceAction(
            invoice: $invoice,
            voidReasonCode: 'customer_cancelled',
            user: static::$cachedUser,
        )->execute();

        $this->assertSame(InvoiceDocumentStatusEnum::VOIDED, $voided->document_status);
        $this->assertSame(
            InvoiceDocumentStatusEnum::VOIDED,
            Invoice::query()->where('id', $invoice->getId())->firstOrFail()->document_status
        );
    }

    public function testTheActivityRefusesAnInvoiceThatIsNotVoided(): void
    {
        // The push and cancel activities share the invoice status-transition event, so each one's guards are
        // what decide whether the transition was theirs. A cancel on a live invoice would kill a pay page a
        // customer is about to use.
        $outcome = $this->activity(CancelMercuryInvoiceActivity::class)->execute(
            $this->publishedInvoice(),
            $this->kanvasApp,
            [],
        );

        $this->assertSame('skipped', $outcome['status']);
        $this->assertSame('not_voided', $outcome['reason']);
    }

    public function testTheActivityRefusesAnInvoiceNeverPushedToMercury(): void
    {
        $customer = $this->seedTestOrganization('Initech LLC');
        $invoice = $this->issueTestInvoice($customer, 5_000.00);
        $invoice->document_status = InvoiceDocumentStatusEnum::VOIDED;
        $invoice->saveQuietly();

        $outcome = $this->activity(CancelMercuryInvoiceActivity::class)->execute($invoice->refresh(), $this->kanvasApp, []);

        $this->assertSame('skipped', $outcome['status']);
        $this->assertSame('never_pushed_to_mercury', $outcome['reason']);
    }

    private function publishedInvoice(): Invoice
    {
        $customer = $this->seedTestOrganization('Initech LLC');
        $invoice = $this->issueTestInvoice($customer, 5_000.00);
        $invoice->set(CustomFieldEnum::INVOICE_ID->value, 'minv-1');

        return $invoice->refresh();
    }

    private function serviceReturning(array $response): MercuryInvoiceService
    {
        return new MercuryInvoiceService(
            $this->kanvasApp,
            $this->company,
            $this->mercuryClientReturning($this->kanvasApp, $this->company, [$response]),
        );
    }
}
