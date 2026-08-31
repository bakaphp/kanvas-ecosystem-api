<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Accounting;

use Kanvas\Intelligence\Agents\Attributes\AgentTypeDefinition;
use Kanvas\Intelligence\Agents\Neuron\BaseRagAgent;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\ListOverdueInvoicesTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\QueryArAgingTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\QueryBalanceSheetTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\QueryCashPositionTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\QueryDataFreshnessTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\QueryPnlTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\QueryRecentExpensesTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\QueryTrialBalanceTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\TopLatePayersTool;
use Kanvas\Intelligence\Agents\Traits\HasTemporalContext;
use NeuronAI\Agent\SystemPrompt;
use Override;

/**
 * CFO-style read-only agent.
 *
 * Read-only: every tool is a query against the Scribe Reports repositories or the GL directly.
 * The agent does NOT write to the books — no posting, no Voids, no Approves, no rate changes. CFO advice
 * is given on top of the deterministic numbers the tools return.
 *
 * query_data_freshness must run FIRST on every turn — the agent has to know how stale the books are
 * before it states any number.
 *
 * Defaults to the agent's `role` JSON for system-prompt content (background/steps/output). When the agent
 * has no `role`, falls back to a hard-coded CFO-shaped prompt that emphasizes data-freshness checks.
 */
#[AgentTypeDefinition(
    name: 'CFO Agent',
    description: 'Read-only CFO assistant — answers questions about company finances using the Scribe '
        . 'reports (Balance Sheet, P&L, Trial Balance, AR Aging) and direct GL queries. Defaults to '
        . 'data-freshness check before any numeric statement.',
    provider: 'neuron',
)]
class CFOAgent extends BaseRagAgent
{
    use HasTemporalContext;

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
        ]));
    }

    #[Override]
    public function instructions(): string
    {
        $role = $this->agent->role ?? [];

        $defaultBackground = [
            'You are a CFO-style advisor for a small/medium business. Your job is to answer questions about '
            . 'the company\'s finances using the read-only tools provided. You DO NOT make journal entries, '
            . 'approve expenses, or change anything in the books — you only read and advise.',
            'You speak plainly, with numbers, in the company\'s reporting currency. You round to whole units '
            . 'unless asked for precision.',
        ];

        $defaultSteps = [
            'ALWAYS call query_data_freshness FIRST. If the staleness exceeds 2 days, explicitly tell the user '
            . '"I am answering from N-day-stale data — the last journal entry was posted on YYYY-MM-DD".',
            'For BS/P&L/AR questions, prefer the corresponding report tool over piecing together raw GL.',
            'For "who owes us money" / "who is late" questions, use top_late_payers or list_overdue_invoices.',
            'For "how much cash do we have", use query_cash_position — sums every active Cash-class account.',
            'For "what did we spend on lately", use query_recent_expenses with a reasonable days_back default.',
            'When the user asks about a specific date range, use the user\'s date range — never substitute today.',
        ];

        $defaultOutput = [
            'Lead with the headline number when one exists (e.g. "AR exposure: $42,300 across 7 customers").',
            'Show top 3-5 line items when a list is naturally short; otherwise summarize aggregates.',
            'Flag anomalies the user did not ask about (e.g. "Heads-up: P&L shows -$5,000 from an unbalanced JE — '
            . 'check the trial balance").',
            'Be honest about data freshness. Never invent precision the source doesn\'t support.',
        ];

        $background = array_filter(
            explode("\n", $role['background'] ?? ''),
            fn (string $line): bool => trim($line) !== '',
        );
        $steps = array_filter(
            explode("\n", $role['steps'] ?? ''),
            fn (string $line): bool => trim($line) !== '',
        );
        $output = array_filter(
            explode("\n", $role['output'] ?? ''),
            fn (string $line): bool => trim($line) !== '',
        );

        $timezone = $this->company?->timezone ?? 'UTC';
        $contextLines = $this->temporalContextLines($timezone);

        return new SystemPrompt(
            background: array_merge($contextLines, $background !== [] ? $background : $defaultBackground),
            steps: $steps !== [] ? $steps : $defaultSteps,
            output: $output !== [] ? $output : $defaultOutput,
        )->__toString();
    }
}
