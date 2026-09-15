<?php

declare(strict_types=1);

namespace App\Console\Commands\Scribe;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Guild\Organizations\Actions\ImportVendorApproversFromRowsAction;

/**
 * Thin CLI wrapper over ImportVendorApproversFromRowsAction — the matching and linking rules live
 * there, shared with the import_vendor_approvers agent tool. Safe to re-run whenever finance
 * re-exports the sheet; only a vendor whose recorded approver differs is written to.
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

        $result = ImportVendorApproversFromRowsAction::fromFilePath($app, $company, $this->argument('file'))->execute();

        if (isset($result['error'])) {
            $this->error($result['error']);

            return;
        }

        foreach ($result['linked'] as $line) {
            $this->info($line);
        }

        $this->info(
            "Done. {$result['updated']} vendors updated, {$result['created']} vendor organizations created, "
                . "{$result['unchanged']} already up to date."
        );

        if ($result['linked_low_confidence'] !== []) {
            $this->warn('Linked on a single, weak match — please double-check these: ' . implode('; ', $result['linked_low_confidence']));
        }

        if ($result['invalid_email'] !== []) {
            $this->warn('Not a valid email address in the sheet (skipped): ' . implode(', ', $result['invalid_email']));
        }

        if ($result['no_email'] !== []) {
            $this->warn('No approver email in the sheet (skipped): ' . implode(', ', $result['no_email']));
        }

        if ($result['ambiguous'] !== []) {
            $this->warn('Multiple vendor Organizations could match (skipped, resolve manually): ' . implode('; ', $result['ambiguous']));
        }
    }
}
