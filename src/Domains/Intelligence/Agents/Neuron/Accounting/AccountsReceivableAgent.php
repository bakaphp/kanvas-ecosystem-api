<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Accounting;

use Kanvas\Intelligence\Agents\Attributes\AgentTypeDefinition;
use Kanvas\Intelligence\Agents\Neuron\SystemUserAgent;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\FindCustomerTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\FindInvoiceTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\ListOverdueInvoicesTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\QueryArAgingTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\QueryDataFreshnessTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\TopLatePayersTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Sales\FindSalesOrderTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Sales\ListOpenSalesOrdersTool;
use Override;

/**
 * The Accounts-Receivable / Sales-Orders teammate — the mirror of the AP agent on the other side of
 * the ledger. It answers "who owes US / what's overdue / what's the sales pipeline / look up sales
 * order #X" over receivables (Scribe invoices) + customer orders (Souk).
 *
 * Extends SystemUserAgent (internal teammate: it IS a Kanvas user, has identity + ledger memory).
 * Read-only: it does not create or change orders/invoices.
 *
 * Scope split (deliberate): this agent owns SALES orders (customer orders) + receivables; the AP
 * agent owns PURCHASE orders + payables. A sales order is a CUSTOMER order (revenue side), never an
 * AP document — that's why the two agents are separate.
 */
#[AgentTypeDefinition(
    name: 'Accounts Receivable Agent',
    description: 'AR / sales-orders teammate — answers who owes us, AR aging, and looks up customer sales orders '
        . '(Souk) + invoices. Read-only: does not create or change orders or invoices.',
    provider: 'neuron',
    soul: 'You are the Accounts-Receivable teammate. You answer questions about money customers owe us and about '
        . 'customer sales orders, using your read tools. You are precise with numbers. You do NOT create or edit '
        . 'orders or invoices — you read and advise.',
    outputFormat: 'Plain text. Lead with the headline number; short paragraphs; lists only for distinct items.',
)]
class AccountsReceivableAgent extends SystemUserAgent
{
    /**
     * @return list<object>
     */
    #[Override]
    protected function tools(): array
    {
        return array_merge(parent::tools(), [
            new QueryDataFreshnessTool(),
            new QueryArAgingTool(),
            new ListOverdueInvoicesTool(),
            new TopLatePayersTool(),
            new FindInvoiceTool(),
            new FindCustomerTool(),
            new FindSalesOrderTool(),
            new ListOpenSalesOrdersTool(),
        ]);
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
            '- "Look up invoice #X" / "status of invoice X" → find_invoice (one specific invoice by number).',
            '- "Who is customer X" / resolve a customer name to its ERP code → find_customer.',
            '- "Look up sales order #X" → find_sales_order (a sales order is a CUSTOMER order, not a purchase order).',
            '- "What orders are open" / "a customer\'s in-flight orders" / the sales pipeline → list_open_sales_orders.',
            '- If asked about a PURCHASE order or a vendor BILL, say that is Accounts Payable, not your area.',
            '- Lead with the headline, then the top 3-5 items. Be honest about freshness.',
        ]);
    }
}
