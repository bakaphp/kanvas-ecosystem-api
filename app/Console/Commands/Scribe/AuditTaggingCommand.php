<?php

declare(strict_types=1);

namespace App\Console\Commands\Scribe;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Kanvas\Apps\Models\Apps;

/**
 * Reports how much of the existing AP/AR data is actually tagged by reason (GL account/subaccount)
 * and by customer/vendor — the completeness question behind "the data needs to be there first"
 * before any discounting/RMA/variable-cost reporting can be built on top of it. Read-only; changes
 * nothing.
 */
class AuditTaggingCommand extends Command
{
    use KanvasJobsTrait;

    protected $signature = 'scribe:audit-tagging {apps_id} {company_id}';

    protected $description = 'Reports how much of the existing AP bill / AR credit note data has a GL account and subaccount tagged';

    public function handle(): void
    {
        $app = Apps::getById((int) $this->argument('apps_id'));
        $this->overwriteAppService($app);

        $appsId = $app->getId();
        $companyId = (int) $this->argument('company_id');

        $this->auditApBills($appsId, $companyId);
        $this->newLine();
        $this->auditArCreditNotes($appsId, $companyId);
    }

    private function auditApBills(int $appsId, int $companyId): void
    {
        $lines = DB::connection('accounting')
            ->table('bill_lines')
            ->join('bills', 'bills.id', '=', 'bill_lines.bill_id')
            ->where('bills.apps_id', $appsId)
            ->where('bills.companies_id', $companyId)
            ->where('bills.is_deleted', false);

        $total = (clone $lines)->count();
        $withExpenseAccount = (clone $lines)->whereNotNull('bill_lines.expense_account_id')->count();
        $withSubaccount = (clone $lines)->whereNotNull('bill_lines.subaccount_id')->count();

        $this->info('AP bill lines');
        $this->line("  Total: {$total}");
        $this->line('  With a GL expense account: ' . $this->percentage($withExpenseAccount, $total));
        $this->line('  With a subaccount (reason) tagged: ' . $this->percentage($withSubaccount, $total));

        $topSubaccounts = (clone $lines)
            ->join('subaccounts', 'subaccounts.id', '=', 'bill_lines.subaccount_id')
            ->select('subaccounts.sub_code')
            ->selectRaw('count(*) as line_count')
            ->groupBy('subaccounts.sub_code')
            ->orderByDesc('line_count')
            ->limit(5)
            ->get();

        if ($topSubaccounts->isEmpty()) {
            $this->warn('  No subaccounts in use at all yet.');

            return;
        }

        $this->line('  Top subaccounts in use:');
        foreach ($topSubaccounts as $row) {
            $this->line("    {$row->sub_code}: {$row->line_count} lines");
        }
    }

    private function auditArCreditNotes(int $appsId, int $companyId): void
    {
        $lines = DB::connection('accounting')
            ->table('invoice_lines')
            ->join('invoices', 'invoices.id', '=', 'invoice_lines.invoice_id')
            ->where('invoices.apps_id', $appsId)
            ->where('invoices.companies_id', $companyId)
            ->where('invoices.document_type', 'credit_note')
            ->where('invoices.is_deleted', false);

        $total = (clone $lines)->count();
        $withAccount = (clone $lines)->whereNotNull('invoice_lines.account_id')->count();
        $distinctCustomers = (clone $lines)
            ->whereNotNull('invoices.customer_organization_id')
            ->distinct()
            ->count('invoices.customer_organization_id');

        $this->info('AR credit note lines');
        $this->line("  Total: {$total}");
        $this->line('  With a GL account (reason) tagged: ' . $this->percentage($withAccount, $total));
        $this->line("  Distinct customers represented: {$distinctCustomers}");

        $topAccounts = (clone $lines)
            ->join('accounts', 'accounts.id', '=', 'invoice_lines.account_id')
            ->select('accounts.account_number', 'accounts.name')
            ->selectRaw('count(*) as line_count')
            ->groupBy('accounts.account_number', 'accounts.name')
            ->orderByDesc('line_count')
            ->limit(5)
            ->get();

        if ($topAccounts->isEmpty()) {
            $this->warn('  No GL accounts in use at all yet.');

            return;
        }

        $this->line('  Top reason accounts in use:');
        foreach ($topAccounts as $row) {
            $this->line("    {$row->account_number} ({$row->name}): {$row->line_count} lines");
        }
    }

    private function percentage(int $part, int $total): string
    {
        if ($total === 0) {
            return '0 of 0';
        }

        $percent = round(($part / $total) * 100, 1);

        return "{$part} of {$total} ({$percent}%)";
    }
}
