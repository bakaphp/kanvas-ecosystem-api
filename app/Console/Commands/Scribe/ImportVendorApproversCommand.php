<?php

declare(strict_types=1);

namespace App\Console\Commands\Scribe;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Guild\Organizations\Actions\ImportVendorApproversFromRowsAction;
use Kanvas\Support\Excel\NullExcelImport;
use Maatwebsite\Excel\Facades\Excel;

/**
 * One-off import: sets ap_approver_email AND ap_approver_vendor_name on each vendor Organization,
 * and links a real Kanvas User as its OrganizationApprover (creating a minimal User record if no
 * Kanvas account matches that email yet), from a spreadsheet mapping Vendor Name -> Approver Email
 * (e.g. the AP Vendor-Approver List finance maintains). A vendor with no existing Organization match
 * at all gets one created on the fly, so the whole sheet ends up linked — only a genuine tie between
 * SEVERAL similarly-plausible candidates is skipped for manual resolution. Re-run whenever the sheet
 * changes — it's idempotent, safe to run again with an updated file, and only actually writes to a
 * vendor whose recorded approver differs from what the sheet says.
 *
 * Thin CLI wrapper — the actual matching/linking rules live in ImportVendorApproversFromRowsAction,
 * shared with import_vendor_approvers (the agent-callable tool for the same job from an uploaded file).
 */
class ImportVendorApproversCommand extends Command
{
    use KanvasJobsTrait;

    protected $signature = 'scribe:import-vendor-approvers {apps_id} {company_id} {file}';

    protected $description = 'Sets the ap_approver_email/ap_approver_vendor_name custom fields and links an OrganizationApprover on vendor Organizations from a Vendor Name / Approver Email spreadsheet, creating the Organization when none matches';

    public function handle(): void
    {
        $app = Apps::getById((int) $this->argument('apps_id'));
        $this->overwriteAppService($app);

        $company = Companies::getById((int) $this->argument('company_id'));

        $rows = Excel::toArray(new NullExcelImport(), $this->argument('file'))[0] ?? [];

        $result = new ImportVendorApproversFromRowsAction($app, $company, $rows)->execute();

        if (isset($result['error'])) {
            $this->error($result['error']);

            return;
        }

        $this->info(
            "Done. {$result['updated']} vendors updated, {$result['created']} vendor organizations created, "
                . "{$result['unchanged']} already up to date."
        );

        if ($result['resolved_single_candidate'] !== []) {
            $this->info('Linked to their one plausible candidate: ' . implode('; ', $result['resolved_single_candidate']));
        }

        if ($result['no_email'] !== []) {
            $this->warn('No approver email in the sheet (skipped): ' . implode(', ', $result['no_email']));
        }

        if ($result['ambiguous'] !== []) {
            $this->warn('Multiple vendor Organizations could match (skipped, resolve manually): ' . implode('; ', $result['ambiguous']));
        }
    }
}
