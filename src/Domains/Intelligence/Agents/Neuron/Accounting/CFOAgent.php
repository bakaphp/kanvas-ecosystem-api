<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Accounting;

use Kanvas\Intelligence\Agents\Attributes\AgentTypeDefinition;
use Kanvas\Intelligence\Agents\Neuron\SystemUserAgent;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\AnswerQuoteTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\ApplyApPaymentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\ApplyArPaymentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\ConvertQuoteToInvoiceTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\CreateApBillTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\CreateArInvoiceTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\CreateQuoteTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\ExtractInvoiceDataTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\FindBillTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\FindCustomerTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\FindInvoiceTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\FindQuoteTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\FindVendorTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\GenerateInvoicePdfTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\GenerateQuotePdfTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\ListOpenBillsTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\ListOverdueInvoicesTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\QueryArAgingTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\QueryBalanceSheetTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\QueryCashPositionTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\QueryDataFreshnessTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\QueryDueToEmployeesTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\QueryExpenseReportTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\QueryPnlTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\QueryRecentExpensesTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\QueryTrialBalanceTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\SendQuoteTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\TopLatePayersTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Common\GetFileLinkTool;
use Override;

/**
 * The CFO teammate — a system-user agent (it IS a Kanvas user, has its own identity + ledger memory,
 * is @mention-reachable) specialised for finance. Its audience is company staff, so it extends
 * SystemUserAgent alongside its AP/AR counterparts rather than bare BaseRagAgent.
 *
 * Finance operations are available alongside deterministic report/query tools. Financial records are
 * only created, issued, approved, or paid when staff explicitly request the operation.
 *
 * query_data_freshness must run FIRST on every turn — the agent has to know how stale the books are
 * before it states any number.
 */
#[AgentTypeDefinition(
    name: 'CFO Agent',
    description: 'CFO assistant — answers company finance questions using Scribe reports and GL queries, '
        . 'and can create quotes, AR invoices, AP bills, and apply invoice/bill payments when explicitly '
        . 'requested by the user. Defaults to a data-freshness check before numeric statements.',
    provider: 'neuron',
    soul: 'You are the CFO teammate. You answer questions about company finances and can perform '
        . 'requested finance operations: create quotes, AR invoices, AP bills, and apply invoice or bill '
        . 'payments. Never create, issue, approve, send, or apply a payment unless the user explicitly '
        . 'asks you to do so. You do not make journal entries, approve expenses, or change rates. Speak '
        . 'plainly, with numbers, in the company\'s reporting currency, and round to whole units unless '
        . 'asked for precision.',
    outputFormat: 'Plain text. Lead with the headline number; short paragraphs; lists only for distinct items.',
)]
class CFOAgent extends SystemUserAgent
{
    #[Override]
    protected function tools(): array
    {
        return array_merge(parent::tools(), $this->addToolContext([
            new QueryDataFreshnessTool(),
            new QueryBalanceSheetTool(),
            new QueryPnlTool(),
            new QueryTrialBalanceTool(),
            new QueryArAgingTool(),
            new ListOverdueInvoicesTool(),
            new TopLatePayersTool(),
            new QueryCashPositionTool(),
            new QueryRecentExpensesTool(),
            new QueryDueToEmployeesTool(),
            new QueryExpenseReportTool(),
            new FindCustomerTool(),
            new FindVendorTool(),
            new CreateQuoteTool(),
            new FindQuoteTool(),
            new SendQuoteTool(),
            new AnswerQuoteTool(),
            new ConvertQuoteToInvoiceTool(),
            new GenerateQuotePdfTool(),
            new GenerateInvoicePdfTool(),
            new FindInvoiceTool(),
            new CreateArInvoiceTool(),
            new ApplyArPaymentTool(),
            new CreateApBillTool(),
            new ApplyApPaymentTool(),
            new FindBillTool(),
            new ListOpenBillsTool(),
            new ExtractInvoiceDataTool(),
            new GetFileLinkTool(),
        ]));
    }

    #[Override]
    public function instructions(): string
    {
        return parent::instructions() . "\n\n" . $this->cfoGuidance();
    }

    private function cfoGuidance(): string
    {
        return implode("\n", [
            '## How to handle finance questions',
            '- Report tools are read-only: do not make journal entries, approve expenses, or change rates. '
            . 'Use finance-document write tools only for operations the user explicitly requests.',
            '- ALWAYS call query_data_freshness FIRST. If the staleness exceeds 2 days, say so explicitly: '
            . '"I am answering from N-day-stale data — the last journal entry was posted on YYYY-MM-DD".',
            '- For BS/P&L/AR questions, prefer the corresponding report tool over piecing together raw GL.',
            '- "Who owes us money" / "who is late" → top_late_payers or list_overdue_invoices.',
            '- "How much cash do we have" → query_cash_position, which sums every active Cash-class account.',
            '- "What did we spend on lately" → query_recent_expenses with a reasonable days_back default.',
            '- "What do we owe our own staff" / "who is waiting on a reimbursement" → query_due_to_employees. '
            . 'That liability is Due to Employees, a separate account from Accounts Payable — never fold it into '
            . 'AP numbers or answer it with query_ap_aging.',
            '- "What did we spend on X last month" / an expense report for a period / the employee-paid vs '
            . 'company-paid split → query_expense_report. It counts approved expenses only, so say so when the '
            . 'number looks lower than someone expects — pending claims are not in it.',
            '- When the user gives a date range, use THEIR range — never substitute today.',
            '- You can create quotes, AR invoices, AP bills, and apply invoice/bill payments, but only when '
            . 'the user explicitly requests that operation. Ask for missing customer/vendor, amount, or '
            . 'payment-reference details rather than guessing.',
            '- Use find_customer or find_vendor to resolve names and ask the user to choose when a match is ambiguous.',
            '- Use find_quote, find_invoice, or find_bill to identify an existing record before acting on it; '
            . 'use create_quote/create_ar_invoice/create_ap_bill only for an explicitly requested new record.',
            '- Apply AR/AP payments only after the user explicitly confirms that a real payment has been made '
            . 'and gives the amount and payment reference. Never infer that an invoice or bill has been paid.',
            '- Quotes and invoices can be generated as PDFs; use get_file_link to return a file link when available.',
            '- Lead with the headline number when one exists (e.g. "AR exposure: $42,300 across 7 customers"), '
            . 'show the top 3-5 line items when the list is naturally short, and summarize aggregates otherwise.',
            '- Flag anomalies nobody asked about (e.g. "Heads-up: P&L shows -$5,000 from an unbalanced JE — '
            . 'check the trial balance"). Never invent precision the source does not support.',
        ]);
    }
}
