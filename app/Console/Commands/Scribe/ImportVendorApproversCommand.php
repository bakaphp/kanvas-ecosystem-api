<?php

declare(strict_types=1);

namespace App\Console\Commands\Scribe;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Guild\Organizations\Actions\CreateOrganizationAction;
use Kanvas\Guild\Organizations\DataTransferObject\Organization as OrganizationData;
use Kanvas\Guild\Organizations\Models\Organization;
use Kanvas\Guild\Organizations\Models\OrganizationApprover;
use Kanvas\Guild\Organizations\Services\OrganizationVendorMatcherService;
use Kanvas\Scribe\Approvals\Enums\OrganizationApproverCustomFieldEnum;
use Kanvas\Support\Excel\NullExcelImport;
use Maatwebsite\Excel\Facades\Excel;

/**
 * One-off import: sets ap_approver_email AND ap_approver_vendor_name on each vendor Organization,
 * and links a real Kanvas User as its OrganizationApprover (creating a minimal User record if no
 * Kanvas account matches that email yet), from a spreadsheet mapping Vendor Name -> Approver Email
 * (e.g. the AP Vendor-Approver List finance maintains). A vendor with no existing Organization match
 * at all gets one created on the fly, so the whole sheet ends up linked — a vendor with SEVERAL
 * possible matches is still skipped for manual resolution, since auto-picking one could silently
 * misfile it against the wrong existing Organization. Re-run whenever the sheet changes — it's
 * idempotent, safe to run again with an updated file.
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

        $header = $this->findHeaderRow($rows);

        if ($header === null) {
            $this->error('Could not find a header row containing "Vendor Name" and "Approver Email" columns.');

            return;
        }

        [$headerIndex, $vendorColumn, $emailColumn] = $header;

        $updated = 0;
        $created = 0;
        $ambiguous = [];
        $noEmail = [];

        foreach (array_slice($rows, $headerIndex + 1) as $row) {
            $vendorName = trim((string) ($row[$vendorColumn] ?? ''));
            $approverEmail = trim((string) ($row[$emailColumn] ?? ''));

            if ($vendorName === '') {
                continue;
            }

            if ($approverEmail === '') {
                $noEmail[] = $vendorName;

                continue;
            }

            $match = OrganizationVendorMatcherService::match($app, $company, $vendorName);

            if ($match->isMatched()) {
                $this->linkVendorApprover($match->organization, $vendorName, $approverEmail);
                $updated++;

                continue;
            }

            if ($match->candidates !== []) {
                $names = implode(', ', array_map(static fn (Organization $o): string => $o->name, $match->candidates));
                $ambiguous[] = "{$vendorName} (candidates: {$names})";

                continue;
            }

            $organization = new CreateOrganizationAction(
                new OrganizationData(
                    company: $company,
                    user: $company->user,
                    app: $app,
                    name: $vendorName,
                ),
            )->execute();

            $this->linkVendorApprover($organization, $vendorName, $approverEmail);
            $created++;
        }

        $this->info("Done. {$updated} vendors updated, {$created} vendor organizations created.");

        if ($noEmail !== []) {
            $this->warn('No approver email in the sheet (skipped): ' . implode(', ', $noEmail));
        }

        if ($ambiguous !== []) {
            $this->warn('Multiple vendor Organizations could match (skipped, resolve manually): ' . implode('; ', $ambiguous));
        }
    }

    private function linkVendorApprover(Organization $organization, string $vendorName, string $approverEmail): void
    {
        $organization->set(OrganizationApproverCustomFieldEnum::APPROVER_EMAIL->value, $approverEmail);
        $organization->set(OrganizationApproverCustomFieldEnum::VENDOR_NAME->value, $vendorName);
        OrganizationApprover::linkApproverEmail($organization, $approverEmail);
        $this->info("{$vendorName} -> {$organization->name} -> {$approverEmail}");
    }

    /**
     * @param array<int, array<int, mixed>> $rows
     *
     * @return array{0: int, 1: int, 2: int}|null
     */
    private function findHeaderRow(array $rows): ?array
    {
        foreach ($rows as $index => $row) {
            $vendorColumn = array_search('Vendor Name', $row, true);
            $emailColumn = array_search('Approver Email', $row, true);

            if ($vendorColumn !== false && $emailColumn !== false) {
                return [$index, $vendorColumn, $emailColumn];
            }
        }

        return null;
    }
}
