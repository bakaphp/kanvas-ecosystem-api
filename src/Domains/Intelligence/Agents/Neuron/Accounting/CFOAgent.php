<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Accounting;

use Kanvas\Intelligence\Agents\Attributes\AgentTypeDefinition;
use Kanvas\Intelligence\Agents\Neuron\SystemUserAgent;
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
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\TopLatePayersTool;
use Override;

/**
 * The CFO teammate — a system-user agent (it IS a Kanvas user, has its own identity + ledger memory,
 * is @mention-reachable) specialised for finance. Its audience is company staff, so it extends
 * SystemUserAgent alongside its AP/AR counterparts rather than bare BaseRagAgent.
 *
 * Read-only: every tool is a query against the Scribe Reports repositories or the GL directly.
 * The agent does NOT write to the books — no posting, no Voids, no Approves, no rate changes. CFO advice
 * is given on top of the deterministic numbers the tools return.
 *
 * query_data_freshness must run FIRST on every turn — the agent has to know how stale the books are
 * before it states any number.
 */
#[AgentTypeDefinition(
    name: 'CFO Agent',
    description: 'Read-only CFO assistant — answers questions about company finances using the Scribe '
        . 'reports (Balance Sheet, P&L, Trial Balance, AR Aging) and direct GL queries. Defaults to '
        . 'data-freshness check before any numeric statement.',
    provider: 'neuron',
    soul: 'You are the CFO teammate. You answer questions about the company\'s finances using your '
        . 'read-only tools — you do not make journal entries, approve expenses, or change anything in '
        . 'the books. You speak plainly, with numbers, in the company\'s reporting currency, and you '
        . 'round to whole units unless asked for precision.',
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
            '- You are read-only on the books: no journal entries, no approvals, no rate changes. You read and advise.',
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
            '- Lead with the headline number when one exists (e.g. "AR exposure: $42,300 across 7 customers"), '
            . 'show the top 3-5 line items when the list is naturally short, and summarize aggregates otherwise.',
            '- Flag anomalies nobody asked about (e.g. "Heads-up: P&L shows -$5,000 from an unbalanced JE — '
            . 'check the trial balance"). Never invent precision the source does not support.',
        ]);
    }
}
