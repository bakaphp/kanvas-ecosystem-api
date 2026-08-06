<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Accounting;

use Kanvas\Intelligence\Agents\Attributes\AgentTypeDefinition;
use Kanvas\Intelligence\Agents\Neuron\SystemUserAgent;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\FindCustomerTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\FindInvoiceTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\ListOverdueInvoicesTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\MatchInvoicesForPaymentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\QueryArAgingTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\QueryDataFreshnessTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\TopLatePayersTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Acumatica\AddInvoiceNoteTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Acumatica\ApplyArPaymentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Acumatica\AttachInvoiceFileTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Acumatica\CreateArCreditMemoTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Acumatica\CreateArInvoiceTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Acumatica\VoidArInvoiceTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Sales\CreateSampleOrderTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Sales\FindProductTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Sales\FindSalesOrderTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Sales\ListOpenSalesOrdersTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Sales\SalesByCustomerTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Sales\SalesByProductTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Sales\SalesRevenueTool;
use Override;

/**
 * The Accounts-Receivable / Sales-Orders teammate — the mirror of the AP agent on the other side of
 * the ledger. It answers "who owes US / what's overdue / what's the sales pipeline / look up sales
 * order #X" over receivables (Scribe invoices) + customer orders (Souk).
 *
 * Extends SystemUserAgent (internal teammate: it IS a Kanvas user, has identity + ledger memory).
 * Mostly read-only, but create_ar_invoice/void_ar_invoice/apply_ar_payment write for real, bypassing human approval — only on explicit request.
 *
 * Scope split (deliberate): this agent owns SALES orders (customer orders) + receivables; the AP
 * agent owns PURCHASE orders + payables. A sales order is a CUSTOMER order (revenue side), never an
 * AP document — that's why the two agents are separate.
 */
#[AgentTypeDefinition(
    name: 'Accounts Receivable Agent',
    description: 'AR / sales-orders teammate — answers who owes us, AR aging, and looks up customer sales orders '
        . '(Souk) + invoices, and can create+push or void an invoice+cash receipt on explicit request.',
    provider: 'neuron',
    soul: 'You are the Accounts-Receivable teammate. You answer questions about money customers owe us and about '
        . 'customer sales orders, using your read tools. You are precise with numbers. create_ar_invoice, '
        . 'void_ar_invoice, and apply_ar_payment write straight to whichever Acumatica tenant is configured — '
        . 'only call any of them when the user explicitly asks you to, never on your own initiative.',
    outputFormat: 'Plain text. Lead with the headline number; short paragraphs; lists only for distinct items.',
)]
class AccountsReceivableAgent extends SystemUserAgent
{
    #[Override]
    protected function tools(): array
    {
        return array_merge(parent::tools(), $this->addToolContext([
            new QueryDataFreshnessTool(),
            new QueryArAgingTool(),
            new ListOverdueInvoicesTool(),
            new TopLatePayersTool(),
            new FindInvoiceTool(),
            new FindCustomerTool(),
            new FindSalesOrderTool(),
            new ListOpenSalesOrdersTool(),
            new FindProductTool(),
            new CreateSampleOrderTool(),
            new SalesByCustomerTool(),
            new SalesByProductTool(),
            new SalesRevenueTool(),
            new MatchInvoicesForPaymentTool(),
            new CreateArInvoiceTool(),
            new VoidArInvoiceTool(),
            new ApplyArPaymentTool(),
            new CreateArCreditMemoTool(),
            new AddInvoiceNoteTool(),
            new AttachInvoiceFileTool(),
        ]));
    }

    #[Override]
    public function instructions(): string
    {
        return parent::instructions() . "\n\n" . $this->arGuidance();
    }

    private function arGuidance(): string
    {
        return implode("\n", [
            '## How to handle Accounts-Receivable / sales-order questions',
            '- Call query_data_freshness first; if the sync is more than 2 days stale, say so before quoting numbers.',
            '- "Who owes us" / "AR aging" / "biggest late payers" → query_ar_aging, list_overdue_invoices, top_late_payers.',
            '- "What invoices are overdue for customer X" → list_overdue_invoices with the customer parameter set — '
            . 'not list_open_sales_orders, which is for purchase/sales orders, not invoices.',
            '- "Look up invoice #X" / "status of invoice X" → find_invoice (one specific invoice by number).',
            '- "Who is customer X" / resolve a customer name to its ERP code → find_customer.',
            '- "Look up sales order #X" → find_sales_order (a sales order is a CUSTOMER order, not a purchase order).',
            '- "What orders are open" / "a customer\'s in-flight orders" / the sales pipeline → list_open_sales_orders.',
            '- "Top customers" / "biggest buyers" → sales_by_customer. "Best sellers" / "top products" → sales_by_product. "Revenue this quarter / trend" → sales_revenue (set by_month for a trend). All exclude draft/canceled orders; be clear about the date range.',
            '- "Send a sample" / "give a reviewer a free unit" → first find_product to turn the product NAME into a SKU, then create_sample_order (customer email+name, SKU, qty). If the customer email is missing, ask for it — it is a real shipment. It creates a $0 DRAFT in Kanvas; tell the user it pushes to the ERP only after a human approves it.',
            '- If asked about a PURCHASE order or a vendor BILL, say that is Accounts Payable, not your area.',
            '- "Create an invoice for customer X" → create_ar_invoice, only when the user explicitly asks for it '
            . '— it writes straight to Acumatica, bypassing human approval.',
            '- "Void/cancel/undo that invoice" → void_ar_invoice, given the invoice_id from create_ar_invoice.',
            '- "Record a payment from customer X against invoice Y" → apply_ar_payment, only when the user '
            . 'explicitly asks to record a real payment. Needs the invoice_id, amount, and a payment reference.',
            '- "Issue a standalone credit memo for customer X" (e.g. a back-end rebate) → create_ar_credit_memo, '
            . 'only on explicit request. Not tied to any invoice — needs the customer name, a reference (e.g. '
            . 'the Credit Request Form\'s Request Reference No), and one or more lines with a Control Acct# and '
            . 'amount each.',
            '- "Add a note to invoice/credit memo Y" → add_invoice_note; "attach this file to invoice/credit memo '
            . 'Y" → attach_invoice_file. Both require the document to already be pushed to Acumatica.',
            '- Lead with the headline, then the top 3-5 items. Be honest about freshness.',
        ]);
    }
}
