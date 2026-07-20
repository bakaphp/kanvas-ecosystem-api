<?php

declare(strict_types=1);

namespace Tests\Scribe\Search;

use Illuminate\Support\Carbon;
use Kanvas\Scribe\Bills\Enums\BillCollectionStateEnum;
use Kanvas\Scribe\Bills\Enums\BillDocumentStatusEnum;
use Kanvas\Scribe\Bills\Enums\PaymentStatusHintEnum;
use Kanvas\Scribe\Bills\Models\Bill;
use Kanvas\Scribe\Expenses\Enums\ExpensePaidByEnum;
use Kanvas\Scribe\Expenses\Enums\ExpenseReimbursementStatusEnum;
use Kanvas\Scribe\Expenses\Enums\ExpenseStatusEnum;
use Kanvas\Scribe\Expenses\Models\Expense;
use Kanvas\Scribe\Invoices\Enums\DocumentTypeEnum;
use Kanvas\Scribe\Invoices\Enums\InvoiceCollectionStateEnum;
use Kanvas\Scribe\Invoices\Enums\InvoiceDocumentStatusEnum;
use Kanvas\Scribe\Invoices\Models\Invoice;
use Kanvas\Scribe\Quotes\Enums\QuoteStatusEnum;
use Kanvas\Scribe\Quotes\Models\Quote;
use Kanvas\Scribe\SalesReceipts\Enums\SalesReceiptStatusEnum;
use Kanvas\Scribe\SalesReceipts\Models\SalesReceipt;
use Tests\TestCase;

/**
 * The Scribe document search docs (Invoice/Quote/SalesReceipt/Bill/Expense) must serialize into a shape the
 * Typesense/Algolia engines can index and query. The three things the raw toArray() gets wrong and that these
 * assertions pin down: the doc `id` must be a string (Typesense rejects int ids), enum-cast statuses must be
 * flat string values (not enum objects), and Carbon date columns must be int64 timestamps (not date strings).
 */
class ScribeSearchableArrayTest extends TestCase
{
    public function testInvoiceSearchableArrayShape(): void
    {
        $invoice = new Invoice();
        $invoice->id = 4242;
        $invoice->uuid = 'invoice-uuid';
        $invoice->invoice_number = 'INV-1001';
        $invoice->billable_display_name = 'ACME Corp';
        $invoice->billable_email = 'ap@acme.test';
        $invoice->external_id = 'QBO-55';
        $invoice->document_type = DocumentTypeEnum::INVOICE;
        $invoice->document_status = InvoiceDocumentStatusEnum::PAID;
        $invoice->collection_state = InvoiceCollectionStateEnum::CURRENT;
        $invoice->currency = 'USD';
        $invoice->total_base = 1180.0;
        $invoice->issued_date = Carbon::parse('2026-06-15');
        $invoice->created_at = Carbon::parse('2026-06-15 10:00:00');

        $doc = $invoice->toSearchableArray();

        $this->assertSame('Kanvas\Scribe\Invoices\Models\Invoice::4242', $doc['objectID']);
        $this->assertSame('4242', $doc['id'], 'Typesense requires the document id to be a string.');
        $this->assertIsString($doc['id']);
        $this->assertSame('INV-1001', $doc['invoice_number']);
        $this->assertSame('ACME Corp', $doc['billable_display_name']);
        $this->assertSame('invoice', $doc['document_type']);
        $this->assertSame('paid', $doc['document_status']);
        $this->assertSame('current', $doc['collection_state']);
        $this->assertIsInt($doc['issued_date'], 'issued_date must be an int64 timestamp.');
        $this->assertIsInt($doc['created_at'], 'created_at must be an int64 timestamp.');
        $this->assertTrue($invoice->shouldBeSearchable());
    }

    public function testQuoteSearchableArrayShape(): void
    {
        $quote = new Quote();
        $quote->id = 7;
        $quote->uuid = 'quote-uuid';
        $quote->quote_number = 'Q-2001';
        $quote->billable_display_name = 'Globex';
        $quote->status = QuoteStatusEnum::SENT;
        $quote->currency = 'USD';
        $quote->total_base = 500.0;
        $quote->issued_date = Carbon::parse('2026-06-01');
        $quote->created_at = Carbon::parse('2026-06-01 09:00:00');

        $doc = $quote->toSearchableArray();

        $this->assertSame('Kanvas\Scribe\Quotes\Models\Quote::7', $doc['objectID']);
        $this->assertSame('7', $doc['id']);
        $this->assertSame('Q-2001', $doc['quote_number']);
        $this->assertSame('sent', $doc['status']);
        $this->assertIsInt($doc['issued_date']);
        $this->assertIsInt($doc['created_at']);
        $this->assertTrue($quote->shouldBeSearchable());
    }

    public function testSalesReceiptSearchableArrayShape(): void
    {
        $receipt = new SalesReceipt();
        $receipt->id = 88;
        $receipt->uuid = 'receipt-uuid';
        $receipt->receipt_number = 'SR-3003';
        $receipt->billable_display_name = 'Walk-in Customer';
        $receipt->status = SalesReceiptStatusEnum::RECORDED;
        $receipt->currency = 'USD';
        $receipt->total_base = 42.5;
        $receipt->receipt_date = Carbon::parse('2026-06-10');
        $receipt->created_at = Carbon::parse('2026-06-10 12:00:00');

        $doc = $receipt->toSearchableArray();

        $this->assertSame('Kanvas\Scribe\SalesReceipts\Models\SalesReceipt::88', $doc['objectID']);
        $this->assertSame('88', $doc['id']);
        $this->assertSame('SR-3003', $doc['receipt_number']);
        $this->assertSame('recorded', $doc['status']);
        $this->assertIsInt($doc['receipt_date']);
        $this->assertIsInt($doc['created_at']);
        $this->assertTrue($receipt->shouldBeSearchable());
    }

    public function testBillSearchableArrayShape(): void
    {
        $bill = new Bill();
        $bill->id = 555;
        $bill->uuid = 'bill-uuid';
        $bill->bill_number = 'BILL-9';
        $bill->vendor_display_name = 'Office Supplies Inc';
        $bill->vendor_email = 'billing@supplies.test';
        $bill->document_status = BillDocumentStatusEnum::RECEIVED;
        $bill->payment_status_hint = PaymentStatusHintEnum::UNPAID;
        $bill->collection_state = BillCollectionStateEnum::CURRENT;
        $bill->currency = 'USD';
        $bill->total_base = 300.0;
        $bill->bill_date = Carbon::parse('2026-06-05');
        $bill->created_at = Carbon::parse('2026-06-05 08:00:00');

        $doc = $bill->toSearchableArray();

        $this->assertSame('Kanvas\Scribe\Bills\Models\Bill::555', $doc['objectID']);
        $this->assertSame('555', $doc['id']);
        $this->assertSame('BILL-9', $doc['bill_number']);
        $this->assertSame('Office Supplies Inc', $doc['vendor_display_name']);
        $this->assertSame('received', $doc['document_status']);
        $this->assertSame('unpaid', $doc['payment_status_hint']);
        $this->assertIsInt($doc['bill_date']);
        $this->assertIsInt($doc['created_at']);
        $this->assertTrue($bill->shouldBeSearchable());
    }

    public function testExpenseSearchableArrayShape(): void
    {
        $expense = new Expense();
        $expense->id = 12;
        $expense->uuid = 'expense-uuid';
        $expense->expense_number = 'EXP-77';
        $expense->vendor_display_name = 'Uber';
        $expense->status = ExpenseStatusEnum::APPROVED;
        $expense->paid_by = ExpensePaidByEnum::EMPLOYEE_PERSONAL;
        $expense->reimbursement_status = ExpenseReimbursementStatusEnum::PENDING;
        $expense->currency = 'USD';
        $expense->total_base = 23.75;
        $expense->expense_date = Carbon::parse('2026-06-12');
        $expense->created_at = Carbon::parse('2026-06-12 07:30:00');

        $doc = $expense->toSearchableArray();

        $this->assertSame('Kanvas\Scribe\Expenses\Models\Expense::12', $doc['objectID']);
        $this->assertSame('12', $doc['id']);
        $this->assertSame('EXP-77', $doc['expense_number']);
        $this->assertSame('approved', $doc['status']);
        $this->assertSame('employee_personal', $doc['paid_by']);
        $this->assertSame('pending', $doc['reimbursement_status']);
        $this->assertIsInt($doc['expense_date']);
        $this->assertIsInt($doc['created_at']);
        $this->assertTrue($expense->shouldBeSearchable());
    }
}
