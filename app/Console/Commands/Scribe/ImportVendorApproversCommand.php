<?php

declare(strict_types=1);

namespace App\Console\Commands\Scribe;

use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Guild\Organizations\Models\Organization;
use Kanvas\Scribe\Approvals\Enums\OrganizationApproverCustomFieldEnum;
use Maatwebsite\Excel\Concerns\ToArray;
use Maatwebsite\Excel\Facades\Excel;

/**
 * One-off import: sets ap_approver_email on each vendor Organization from a spreadsheet mapping
 * Vendor Name -> Approver Email (e.g. the AP Vendor-Approver List finance maintains). Re-run
 * whenever that sheet changes — it's idempotent, safe to run again with an updated file.
 */
class ImportVendorApproversCommand extends Command
{
    protected $signature = 'scribe:import-vendor-approvers {apps_id} {company_id} {file}';

    protected $description = 'Sets the ap_approver_email custom field on vendor Organizations from a Vendor Name / Approver Email spreadsheet';

    public function handle(): void
    {
        $app = Apps::getById((int) $this->argument('apps_id'));
        $company = Companies::getById((int) $this->argument('company_id'));

        $import = new class () implements ToArray {
            public function array(array $array): void
            {
            }
        };

        $rows = Excel::toArray($import, $this->argument('file'))[0] ?? [];

        $header = $this->findHeaderRow($rows);

        if ($header === null) {
            $this->error('Could not find a header row containing "Vendor Name" and "Approver Email" columns.');

            return;
        }

        [$headerIndex, $vendorColumn, $emailColumn] = $header;

        $updated = 0;
        $unmatched = [];
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

            $organization = $this->findOrganization($app, $company, $vendorName);

            if ($organization === null) {
                $unmatched[] = $vendorName;

                continue;
            }

            $organization->set(OrganizationApproverCustomFieldEnum::APPROVER_EMAIL->value, $approverEmail);
            $this->info("{$vendorName} -> {$approverEmail}");
            $updated++;
        }

        $this->info("Done. {$updated} vendors updated.");

        if ($noEmail !== []) {
            $this->warn('No approver email in the sheet (skipped): ' . implode(', ', $noEmail));
        }

        if ($unmatched !== []) {
            $this->warn('No matching vendor Organization found (skipped): ' . implode(', ', $unmatched));
        }
    }

    private function findOrganization(Apps $app, Companies $company, string $vendorName): ?Organization
    {
        $query = Organization::query()
            ->where('apps_id', $app->getId())
            ->where('companies_id', $company->getId());

        return (clone $query)->where('name', $vendorName)->first()
            ?? $query->where('name', 'like', '%' . $vendorName . '%')->first();
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
