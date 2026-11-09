<?php

declare(strict_types=1);

namespace Tests\Scribe\Intelligence;

use Illuminate\Support\Carbon;
use Kanvas\Intelligence\Agents\Enums\ToolOutcomeEnum;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\ApplyArPaymentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\CreateArInvoiceTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\ListPaymentTermsTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\SetInvoiceTermsTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\SetPaymentTermsTool;
use Kanvas\NervousSystem\Ledger\Models\Event as LedgerEvent;
use Kanvas\Scribe\Invoices\Actions\AmendInvoiceAction;
use Kanvas\Scribe\Invoices\Enums\InvoiceDocumentStatusEnum;
use Kanvas\Scribe\Invoices\Models\Invoice;
use Kanvas\Scribe\PaymentTerms\Models\PaymentTerm;
use Tests\Scribe\ScribeTestCase;

/**
 * Every invoice the AR agent made was due the day it was issued (open item 8 of the 2026-11-08 e2e), and no result
 * said so. Terms now come from a company default the agent can set, and every invoice result carries its due date.
 */
class PaymentTermsAgentToolsTest extends ScribeTestCase
{
    protected array $connectionsToTransact = ['mysql', 'ecosystem', 'accounting', 'intelligence', 'crm', 'social'];

    public function test_an_invoice_with_no_terms_is_flagged_due_on_receipt_and_a_company_default_fixes_it(): void
    {
        $this->seedTestOrganization('Terms Flag Co');
        $invoiceTool = new CreateArInvoiceTool()->withContext($this->kanvasApp, $this->company, static::$cachedUser);
        $termsTool = new SetPaymentTermsTool()->withContext($this->kanvasApp, $this->company, static::$cachedUser);
        $listTool = new ListPaymentTermsTool()->withContext($this->kanvasApp, $this->company, static::$cachedUser);

        $empty = $listTool->__invoke();
        $this->assertSame(0, $empty['count']);
        $this->assertStringContainsString('due on receipt', $empty['message']);

        $onReceipt = $invoiceTool->__invoke(customer_name: 'Terms Flag Co', memo: 'Consulting', amount: 500.0);
        $this->assertTrue($onReceipt['created'], json_encode($onReceipt));
        $this->assertSame(0, $onReceipt['net_terms_days']);
        $this->assertStringContainsString('Due on receipt: nothing set payment terms', $onReceipt['terms_note']);

        $own = $invoiceTool->__invoke(
            customer_name: 'Terms Flag Co',
            memo: 'Workshop',
            amount: 700.0,
            net_terms_days: 10,
        );
        $this->assertSame(10, $own['net_terms_days']);
        $this->assertSame(Carbon::today()->addDays(10)->toDateString(), $own['due_date']);
        $this->assertArrayNotHasKey('terms_note', $own);

        $created = $termsTool->__invoke(name: 'Net 30', net_days: 30, is_default: true);
        $this->assertTrue($created['saved'], json_encode($created));
        $this->assertSame('created', $created['action']);
        $this->assertTrue($created['is_default']);
        $this->assertNotNull(PaymentTerm::defaultFor($this->kanvasApp, $this->company));

        $defaulted = $invoiceTool->__invoke(customer_name: 'Terms Flag Co', memo: 'Hosting', amount: 120.0);
        $this->assertSame(30, $defaulted['net_terms_days']);
        $this->assertSame(Carbon::today()->addDays(30)->toDateString(), $defaulted['due_date']);
        $this->assertArrayNotHasKey('terms_note', $defaulted);
        $this->assertSame(Carbon::today()->addDays(30)->toDateString(), Invoice::getById($defaulted['invoice_id'])->due_date->toDateString());

        $updated = $termsTool->__invoke(name: 'Net 30', net_days: 45);
        $this->assertSame('updated', $updated['action']);
        $this->assertTrue($updated['is_default'], 'an update that says nothing about default keeps it');
        $this->assertSame(45, $updated['net_days']);

        $second = $termsTool->__invoke(name: 'Due on receipt', net_days: 0, is_default: true);
        $this->assertTrue($second['is_default']);
        $listed = $listTool->withContext($this->kanvasApp, $this->company, static::$cachedUser)->__invoke();
        $this->assertSame(1, collect($listed['payment_terms'])->where('is_default', true)->count(), 'one default at a time');
        $this->assertSame('Due on receipt', $listed['payment_terms'][0]['name'], 'the default lists first');

        $this->assertSame('invalid_terms', $termsTool->__invoke(name: ' ', net_days: 30)['reason']);
        $this->assertSame('invalid_terms', $termsTool->__invoke(name: 'Negative', net_days: -1)['reason']);
    }

    public function test_set_invoice_terms_changes_a_draft_directly_and_an_issued_invoice_as_an_amendment(): void
    {
        $customer = $this->seedTestOrganization('Terms Change Co');
        $terms = new SetInvoiceTermsTool()->withContext($this->kanvasApp, $this->company, static::$cachedUser);

        $draftResult = new CreateArInvoiceTool()
            ->withContext($this->kanvasApp, $this->company, static::$cachedUser)
            ->__invoke(customer_name: 'Terms Change Co', memo: 'Design', amount: 400.0);
        $draft = $terms->__invoke(invoice_id: $draftResult['invoice_id'], net_terms_days: 15);
        $this->assertTrue($draft['changed'], json_encode($draft));
        $this->assertSame(InvoiceDocumentStatusEnum::DRAFT->value, $draft['document_status']);
        $this->assertSame(15, $draft['net_terms_days']);
        $this->assertSame(Carbon::today()->addDays(15)->toDateString(), $draft['due_date']);
        $this->assertArrayNotHasKey('terms_note', $draft);
        $this->assertSame(1, LedgerEvent::query()->where('event_type', 'scribe.invoice.terms_set')->where('source_entity_id', $draftResult['invoice_id'])->count());

        $issued = $this->issueTestInvoice(
            $customer,
            300.0,
            issuedDate: '2026-06-01',
            dueDate: '2026-06-01',
        );
        $noReason = $terms->__invoke(invoice_id: $issued->getId(), due_date: '2026-07-15');
        $this->assertFalse($noReason['changed']);
        $this->assertSame('reason_required', $noReason['reason']);
        $this->assertSame('2026-06-01', $issued->fresh()->due_date->toDateString(), 'nothing moved without a reason');

        $amended = $terms->__invoke(invoice_id: $issued->getId(), due_date: '2026-07-15', reason: 'Customer asked for 45 days');
        $this->assertTrue($amended['changed'], json_encode($amended));
        $this->assertSame('2026-06-01', $amended['previous_due_date']);
        $this->assertSame('2026-07-15', $amended['due_date']);
        $this->assertSame('2026-07-15', $issued->fresh()->due_date->toDateString());
        $this->assertSame(1, LedgerEvent::query()->where('event_type', AmendInvoiceAction::LEDGER_EVENT)->where('source_entity_id', $issued->getId())->count());

        $byNet = $terms->__invoke(invoice_id: $issued->getId(), net_terms_days: 60, reason: 'Renegotiated');
        $this->assertSame('2026-07-31', $byNet['due_date'], 'net days count from the issue date, not from today');

        $this->assertSame('terms_required', $terms->__invoke(invoice_id: $issued->getId(), reason: 'x')['reason']);
        $this->assertSame('invalid_due_date', $terms->__invoke(invoice_id: $issued->getId(), due_date: 'next week', reason: 'x')['reason']);
        $this->assertSame('invalid_due_date', $terms->__invoke(invoice_id: $issued->getId(), due_date: '2026-02-30', reason: 'x')['reason'], 'createFromFormat would roll it to March 2');
        $this->assertSame('2026-07-31', $issued->fresh()->due_date->toDateString(), 'a bad date moves nothing');
        $this->assertSame('invoice_not_found', $terms->__invoke(invoice_id: 999999999, due_date: '2026-07-15')['reason']);
    }

    public function test_a_paid_invoice_keeps_its_terms(): void
    {
        $customer = $this->seedTestOrganization('Terms Paid Co');
        $invoice = $this->issueTestInvoice($customer, 300.0);
        new ApplyArPaymentTool()
            ->withContext($this->kanvasApp, $this->company, static::$cachedUser)
            ->__invoke(
                invoice_id: $invoice->getId(),
                amount: 300.0,
                reference: 'PAID-1',
                method: 'wire',
            );
        $this->assertSame(InvoiceDocumentStatusEnum::PAID, $invoice->fresh()->document_status);

        $result = new SetInvoiceTermsTool()
            ->withContext($this->kanvasApp, $this->company, static::$cachedUser)
            ->__invoke(invoice_id: $invoice->getId(), due_date: '2026-08-01', reason: 'Late request');

        $this->assertFalse($result['changed']);
        $this->assertSame(ToolOutcomeEnum::DENIED->value, $result['outcome']);
        $this->assertSame('invoice_closed', $result['reason']);
        $this->assertSame('2026-07-15', $invoice->fresh()->due_date->toDateString());
    }
}
