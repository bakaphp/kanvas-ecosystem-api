<?php

declare(strict_types=1);

namespace Tests\Scribe\Reports;

use Illuminate\Support\Carbon;
use Kanvas\Scribe\Invoices\Actions\AllocateInvoicePaymentAction;
use Kanvas\Scribe\Invoices\Actions\CreateInvoiceAction;
use Kanvas\Scribe\Invoices\Actions\IssueCreditNoteAction;
use Kanvas\Scribe\Invoices\Actions\VoidCreditNoteAction;
use Kanvas\Scribe\Invoices\DataTransferObject\Invoice as InvoiceData;
use Kanvas\Scribe\Invoices\DataTransferObject\InvoiceLine as InvoiceLineData;
use Kanvas\Scribe\Invoices\Enums\DocumentTypeEnum;
use Kanvas\Scribe\Invoices\Models\Invoice;
use Kanvas\Scribe\Reports\Repositories\ArAgingRepository;
use Kanvas\Scribe\Reports\Repositories\RevenueRepository;
use Spatie\LaravelData\DataCollection;
use Tests\Scribe\ScribeTestCase;

class ReportAsOfTest extends ScribeTestCase
{
    public function test_a_credit_note_still_in_draft_does_not_reduce_revenue(): void
    {
        $customer = $this->seedTestOrganization('Draft Credit Customer');
        $before = $this->revenue();
        $invoice = $this->issueTestInvoice($customer, 100, issuedDate: Carbon::today()->toDateString());
        new CreateInvoiceAction(
            new InvoiceData(
                app: $this->kanvasApp,
                company: $this->company,
                billable: $customer,
                lines: new DataCollection(InvoiceLineData::class, [
                    new InvoiceLineData(description: 'Credit not approved yet', quantity: 1, unit_price_native: 40),
                ]),
                currency: 'USD',
                fx_rate_to_base: 1,
                document_type: DocumentTypeEnum::CREDIT_NOTE,
                issued_date: Carbon::today(),
                parent_invoice_id: $invoice->getId(),
            )
        )->execute();

        $this->assertSame(100.0, round($this->revenue() - $before, 2));
    }

    public function test_aging_for_a_past_day_is_the_receivables_as_they_stood_that_day(): void
    {
        $repository = new ArAgingRepository();
        $endOfMay = Carbon::parse('2026-05-31');
        $owedThen = $repository->generate($this->kanvasApp, $this->company, $endOfMay)->grand_total;
        $owedNow = $repository->generate($this->kanvasApp, $this->company, Carbon::today())->grand_total;
        $customer = $this->seedTestOrganization('Past Aging Customer');

        $this->issueTestInvoice($customer, 100);
        $this->assertSame(
            $owedThen,
            $repository->generate($this->kanvasApp, $this->company, $endOfMay)->grand_total,
            'an invoice issued in June is not owed at the end of May'
        );

        $paidLater = $this->issueTestInvoice($customer, 70);
        $paidLater->forceFill(['issued_date' => '2026-05-10', 'due_date' => '2026-06-10'])->save();
        new AllocateInvoicePaymentAction($paidLater, 70)->execute();

        $this->assertSame(
            round($owedThen + 70, 2),
            round($repository->generate($this->kanvasApp, $this->company, $endOfMay)->grand_total, 2),
            'an invoice issued in May and paid in June was still owed at the end of May'
        );
        $this->assertSame(
            round($owedNow + 100, 2),
            round($repository->generate($this->kanvasApp, $this->company, Carbon::today())->grand_total, 2)
        );
    }

    public function test_a_credit_reversed_after_the_day_asked_for_still_counted_that_day(): void
    {
        $customer = $this->seedTestOrganization('Reversed Credit Customer');
        $owedBefore = $this->owedOn('2026-06-20');
        $invoice = $this->issueTestInvoice($customer, 100, issuedDate: '2026-06-01');
        Carbon::setTestNow('2026-06-10 12:00:00');
        $credit = new IssueCreditNoteAction(
            parentInvoice: $invoice,
            data: new InvoiceData(
                app: $this->kanvasApp,
                company: $this->company,
                billable: $customer,
                lines: new DataCollection(InvoiceLineData::class, [
                    new InvoiceLineData(description: 'Credit', quantity: 1, unit_price_native: 40),
                ]),
                currency: 'USD',
                fx_rate_to_base: 1,
                issued_date: Carbon::today(),
            ),
            user: static::$cachedUser,
        )->execute();
        Carbon::setTestNow('2026-06-25 12:00:00');
        new VoidCreditNoteAction($credit, 'Issued in error', static::$cachedUser)->execute();

        $this->assertSame(60.0, round($this->owedOn('2026-06-20') - $owedBefore, 2));
    }

    public function test_what_an_imported_invoice_says_was_paid_is_not_owed_on_a_past_day(): void
    {
        Carbon::setTestNow('2026-06-10 12:00:00');
        $owedBefore = $this->owedOn('2026-06-20');
        $this->importTestInvoice(total: 100, paid: 40);
        Carbon::setTestNow('2026-06-25 12:00:00');

        $this->assertSame(60.0, round($this->owedOn('2026-06-20') - $owedBefore, 2));
    }

    public function test_a_payment_the_source_system_reports_later_was_still_owed_before(): void
    {
        $owedBefore = $this->owedOn('2026-06-20');
        $externalId = 'LATER-' . uniqid();
        $this->importTestInvoice(total: 100, paid: 0, externalId: $externalId);
        Carbon::setTestNow('2026-06-25 12:00:00');
        $this->importTestInvoice(total: 100, paid: 40, externalId: $externalId);

        $this->assertSame(100.0, round($this->owedOn('2026-06-20') - $owedBefore, 2));
        $this->assertSame(60.0, round($this->owedOn('2026-06-25') - $this->owedOn('2026-05-31'), 2));
    }

    public function test_a_refund_the_source_system_reports_later_leaves_the_earlier_day_as_it_was(): void
    {
        Carbon::setTestNow('2026-06-10 12:00:00');
        $owedBefore = $this->owedOn('2026-06-20');
        $externalId = 'REFUND-' . uniqid();
        $this->importTestInvoice(total: 100, paid: 40, externalId: $externalId);
        Carbon::setTestNow('2026-06-25 12:00:00');
        $this->importTestInvoice(total: 100, paid: 0, externalId: $externalId);

        $this->assertSame(60.0, round($this->owedOn('2026-06-20') - $owedBefore, 2));
    }

    public function test_an_invoice_reopened_by_a_refund_is_owed_again_and_was_not_owed_while_paid(): void
    {
        Carbon::setTestNow('2026-06-10 12:00:00');
        $paidDay = $this->owedOn('2026-06-15');
        $afterRefund = $this->owedOn('2026-06-26');
        $externalId = 'REOPENED-' . uniqid();
        $this->importTestInvoice(total: 100, paid: 100, externalId: $externalId);
        Carbon::setTestNow('2026-06-25 12:00:00');
        $reopened = $this->importTestInvoice(total: 100, paid: 0, externalId: $externalId);
        Carbon::setTestNow('2026-06-30 12:00:00');

        $this->assertNull($reopened->paid_at);
        $this->assertSame(100.0, round($this->owedOn('2026-06-26') - $afterRefund, 2));
        $this->assertSame(0.0, round($this->owedOn('2026-06-15') - $paidDay, 2));
    }

    public function test_what_was_paid_before_the_first_dated_note_stays_paid_on_earlier_days(): void
    {
        Carbon::setTestNow('2026-06-10 12:00:00');
        $owedBefore = $this->owedOn('2026-06-20');
        $externalId = 'LEGACY-' . uniqid();
        $invoice = $this->importTestInvoice(total: 100, paid: 40, externalId: $externalId);
        $invoice->metadata = array_diff_key($invoice->metadata ?? [], [Invoice::EXTERNAL_PAID_HISTORY => true]);
        $invoice->save();
        Carbon::setTestNow('2026-06-25 12:00:00');
        $this->importTestInvoice(total: 100, paid: 60, externalId: $externalId);

        $this->assertSame(60.0, round($this->owedOn('2026-06-20') - $owedBefore, 2));
        $this->assertSame(40.0, round($this->owedOn('2026-06-25') - $this->owedOn('2026-05-31'), 2));
    }

    private function owedOn(string $day): float
    {
        return (float) new ArAgingRepository()
            ->generate($this->kanvasApp, $this->company, Carbon::parse($day))
            ->grand_total;
    }

    private function revenue(): float
    {
        return (float) new RevenueRepository()->generate(
            $this->kanvasApp,
            $this->company,
            Carbon::today()->startOfMonth(),
            Carbon::today()->endOfMonth()
        )->total_net_revenue;
    }
}
