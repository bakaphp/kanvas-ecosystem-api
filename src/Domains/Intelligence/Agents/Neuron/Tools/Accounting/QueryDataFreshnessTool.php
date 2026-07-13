<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Accounting;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use NeuronAI\Tools\Tool;
use Override;

#[AgentTool(name: 'Query Data Freshness')]
class QueryDataFreshnessTool extends Tool
{
    use HasKanvasContext;

    public function __construct()
    {
        parent::__construct(
            name: 'query_data_freshness',
            description: 'Returns when the books were last updated — most recent journal entry posted_at, most '
                . 'recent invoice issued_date, most recent expense expense_date, and counts by document. Use this '
                . 'BEFORE answering any financial question so you can tell the user "I\'m answering from N-day-stale '
                . 'data" when the source isn\'t fresh. Critical for honest CFO advice.',
        );
    }

    #[Override]
    protected function properties(): array
    {
        return [];
    }

    public function __invoke(): array
    {
        $app = $this->app;
        $user = $this->user;
        $company = $user->getCurrentCompany();
        $appId = $app->getId();
        $companyId = $company->getId();
        $today = Carbon::today();

        $lastJe = DB::connection('accounting')->table('journal_entries')
            ->where('apps_id', $appId)
            ->where('companies_id', $companyId)
            ->where('status', 'posted')
            ->orderByDesc('posted_at')
            ->value('posted_at');

        $lastInvoice = DB::connection('accounting')->table('invoices')
            ->where('apps_id', $appId)
            ->where('companies_id', $companyId)
            ->where('is_deleted', false)
            ->whereNotNull('issued_date')
            ->orderByDesc('issued_date')
            ->value('issued_date');

        $lastExpense = DB::connection('accounting')->table('expenses')
            ->where('apps_id', $appId)
            ->where('companies_id', $companyId)
            ->where('is_deleted', false)
            ->orderByDesc('expense_date')
            ->value('expense_date');

        $counts = [
            'journal_entries_posted' => DB::connection('accounting')->table('journal_entries')
                ->where('apps_id', $appId)
                ->where('companies_id', $companyId)
                ->where('status', 'posted')
                ->count(),
            'invoices_issued_or_sent' => DB::connection('accounting')->table('invoices')
                ->where('apps_id', $appId)
                ->where('companies_id', $companyId)
                ->where('is_deleted', false)
                ->whereIn('document_status', ['issued', 'sent', 'paid'])
                ->count(),
            'expenses_approved' => DB::connection('accounting')->table('expenses')
                ->where('apps_id', $appId)
                ->where('companies_id', $companyId)
                ->where('is_deleted', false)
                ->where('status', 'approved')
                ->count(),
        ];

        $stalenessDays = $lastJe !== null
            ? (int) Carbon::parse($lastJe)->diffInDays($today)
            : null;

        return [
            'today' => $today->toDateString(),
            'last_journal_entry_posted_at' => $lastJe,
            'last_invoice_issued_date' => $lastInvoice,
            'last_expense_date' => $lastExpense,
            'staleness_days_since_last_je' => $stalenessDays,
            'is_fresh' => $stalenessDays !== null && $stalenessDays <= 2,
            'counts' => $counts,
        ];
    }
}
