<?php

declare(strict_types=1);

namespace App\Console\Commands\Scribe;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Guild\Organizations\Models\Organization;
use Kanvas\Scribe\Bills\Models\Bill;
use Kanvas\Scribe\Invoices\Models\Invoice;
use Throwable;

/**
 * One-shot backfill: fill in NULL snapshot fields on existing Invoices + Bills by reading the
 * linked Guild Org's BillableInterface / PayeeInterface getters.
 *
 *   - Only touches fields that are currently NULL (never overwrites existing values, including
 *     intentional per-document overrides).
 *   - Requires the row to have customer_organization_id / vendor_organization_id set.
 *   - Idempotent: re-running on already-filled rows is a no-op.
 *
 *   php artisan scribe:backfill-snapshot {app_id} {company_id}
 *   php artisan scribe:backfill-snapshot 2 105 --entity=invoices --dry-run
 *   php artisan scribe:backfill-snapshot 2 105 --entity=bills
 */
class BackfillSnapshotFieldsCommand extends Command
{
    use KanvasJobsTrait;

    protected $signature = 'scribe:backfill-snapshot
                            {app_id}
                            {company_id}
                            {--entity=all : "all", "invoices", or "bills"}
                            {--dry-run : Report what would change without saving}';

    protected $description = 'Backfill NULL billable/vendor snapshot fields on Invoices + Bills from their linked Guild Org. Idempotent; never overwrites existing values.';

    public function handle(): int
    {
        $app = Apps::getById((int) $this->argument('app_id'));
        $company = Companies::getById((int) $this->argument('company_id'));
        $entity = strtolower((string) $this->option('entity'));
        $dryRun = (bool) $this->option('dry-run');

        $this->overwriteAppService($app);

        $this->info("Backfilling snapshot fields for app={$app->name}, company={$company->name}" . ($dryRun ? ' (DRY RUN)' : ''));
        $this->newLine();

        if (in_array($entity, ['all', 'invoices'], true)) {
            $this->backfillInvoices($app, $company, $dryRun);
        }

        if (in_array($entity, ['all', 'bills'], true)) {
            $this->backfillBills($app, $company, $dryRun);
        }

        return self::SUCCESS;
    }

    private function backfillInvoices(Apps $app, Companies $company, bool $dryRun): void
    {
        $query = Invoice::query()
            ->where('apps_id', $app->getId())
            ->where('companies_id', $company->getId())
            ->whereNotNull('customer_organization_id')
            ->where(function ($q): void {
                $q->whereNull('billable_display_name')
                    ->orWhereNull('billable_legal_name')
                    ->orWhereNull('billable_tax_id')
                    ->orWhereNull('billable_email')
                    ->orWhereNull('billing_address_snapshot');
            });

        $total = $query->count();
        $this->info("Invoices candidates: {$total}");

        $updated = 0;
        $skipped = 0;
        $errors = 0;

        $query->chunkById(200, function ($invoices) use (&$updated, &$skipped, &$errors, $company, $app, $dryRun): void {
            foreach ($invoices as $invoice) {
                try {
                    /** @var Organization $org */
                    $org = Organization::getByIdFromCompanyApp(
                        (int) $invoice->customer_organization_id,
                        $company,
                        $app,
                    );
                } catch (Throwable) {
                    ++$skipped;

                    continue;
                }

                $changed = false;
                if ($invoice->billable_display_name === null) {
                    $invoice->billable_display_name = $org->getBillableDisplayName();
                    $changed = true;
                }
                if ($invoice->billable_legal_name === null) {
                    $invoice->billable_legal_name = $org->getBillableLegalName();
                    $changed = true;
                }
                if ($invoice->billable_tax_id === null) {
                    $invoice->billable_tax_id = $org->getBillableTaxId();
                    $changed = true;
                }
                if ($invoice->billable_email === null) {
                    $invoice->billable_email = $org->getBillingEmail();
                    $changed = true;
                }
                if ($invoice->billing_address_snapshot === null) {
                    $invoice->billing_address_snapshot = $org->getBillingAddressArray();
                    $changed = true;
                }

                if (! $changed) {
                    ++$skipped;

                    continue;
                }

                if (! $dryRun) {
                    $invoice->saveQuietly();
                }
                ++$updated;
            }
        });

        $this->info("Invoices: {$updated} updated, {$skipped} skipped, {$errors} errors.");
        $this->newLine();
    }

    private function backfillBills(Apps $app, Companies $company, bool $dryRun): void
    {
        $query = Bill::query()
            ->where('apps_id', $app->getId())
            ->where('companies_id', $company->getId())
            ->whereNotNull('vendor_organization_id')
            ->where(function ($q): void {
                $q->whereNull('vendor_display_name')
                    ->orWhereNull('vendor_legal_name')
                    ->orWhereNull('vendor_tax_id')
                    ->orWhereNull('vendor_email')
                    ->orWhereNull('vendor_address_snapshot');
            });

        $total = $query->count();
        $this->info("Bills candidates: {$total}");

        $updated = 0;
        $skipped = 0;
        $errors = 0;

        $query->chunkById(200, function ($bills) use (&$updated, &$skipped, &$errors, $company, $app, $dryRun): void {
            foreach ($bills as $bill) {
                try {
                    /** @var Organization $org */
                    $org = Organization::getByIdFromCompanyApp(
                        (int) $bill->vendor_organization_id,
                        $company,
                        $app,
                    );
                } catch (Throwable) {
                    ++$skipped;

                    continue;
                }

                $changed = false;
                if ($bill->vendor_display_name === null) {
                    $bill->vendor_display_name = $org->getPayeeDisplayName();
                    $changed = true;
                }
                if ($bill->vendor_legal_name === null) {
                    $bill->vendor_legal_name = $org->getPayeeLegalName();
                    $changed = true;
                }
                if ($bill->vendor_tax_id === null) {
                    $bill->vendor_tax_id = $org->getPayeeTaxId();
                    $changed = true;
                }
                if ($bill->vendor_email === null) {
                    $bill->vendor_email = $org->getPayeeEmail();
                    $changed = true;
                }
                if ($bill->vendor_address_snapshot === null) {
                    $bill->vendor_address_snapshot = $org->getPayeeAddressArray();
                    $changed = true;
                }

                if (! $changed) {
                    ++$skipped;

                    continue;
                }

                if (! $dryRun) {
                    $bill->saveQuietly();
                }
                ++$updated;
            }
        });

        $this->info("Bills: {$updated} updated, {$skipped} skipped, {$errors} errors.");
    }
}
