<?php

declare(strict_types=1);

namespace Kanvas\Guild\Organizations\Actions;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Guild\Organizations\DataTransferObject\Organization as OrganizationData;
use Kanvas\Guild\Organizations\Models\Organization;
use Kanvas\Guild\Organizations\Models\OrganizationApprover;
use Kanvas\Guild\Organizations\Services\OrganizationVendorMatcherService;
use Kanvas\Scribe\Approvals\Enums\OrganizationApproverCustomFieldEnum;
use Kanvas\Support\Excel\NullExcelImport;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Sets ap_approver_email/ap_approver_vendor_name on each vendor Organization and links a real
 * Kanvas User as its OrganizationApprover, from raw spreadsheet rows (Vendor Name -> Approver
 * Email). Shared by ImportVendorApproversCommand (CLI, a file path) and ImportVendorApproversTool
 * (agent-callable, an uploaded Filesystem row) so both stay in sync with the same matching rules.
 *
 * A vendor with no existing Organization match gets one created. A vendor with a single plausible
 * candidate is linked to it — this is weaker evidence than OrganizationVendorMatcherService's own
 * auto-match bar (a lone candidate reaches here specifically because its score sits below that bar),
 * so every such link is reported back in `linked_low_confidence` for the caller to surface prominently
 * rather than treated as a routine success. A genuine tie between several candidates is still skipped
 * for manual resolution. Idempotent — a vendor whose approver is already exactly what the sheet says
 * is left untouched and counted as `unchanged`.
 */
class ImportVendorApproversFromRowsAction
{
    /**
     * @param array<int, array<int, mixed>> $rows raw Excel::toArray() rows, header included
     */
    public function __construct(
        protected readonly Apps $app,
        protected readonly Companies $company,
        protected readonly array $rows,
    ) {
    }

    public static function fromFilePath(Apps $app, Companies $company, string $filePath): self
    {
        return new self($app, $company, Excel::toArray(new NullExcelImport(), $filePath)[0] ?? []);
    }

    /**
     * @return array{updated: int, created: int, unchanged: int, linked: list<string>, linked_low_confidence: list<string>, ambiguous: list<string>, no_email: list<string>}|array{error: string}
     */
    public function execute(): array
    {
        $header = $this->findHeaderRow($this->rows);

        if ($header === null) {
            return ['error' => 'Could not find a header row containing "Vendor Name" and "Approver Email" columns.'];
        }

        [$headerIndex, $vendorColumn, $emailColumn] = $header;

        $report = [
            'updated' => 0,
            'created' => 0,
            'unchanged' => 0,
            'linked' => [],
            'linked_low_confidence' => [],
            'ambiguous' => [],
            'no_email' => [],
        ];

        foreach (array_slice($this->rows, $headerIndex + 1) as $row) {
            $vendorName = trim((string) ($row[$vendorColumn] ?? ''));
            $approverEmail = trim((string) ($row[$emailColumn] ?? ''));

            if ($vendorName === '') {
                continue;
            }

            if ($approverEmail === '') {
                $report['no_email'][] = $vendorName;

                continue;
            }

            $this->importRow($vendorName, $approverEmail, $report);
        }

        return $report;
    }

    /**
     * @param array{updated: int, created: int, unchanged: int, linked: list<string>, linked_low_confidence: list<string>, ambiguous: list<string>, no_email: list<string>} $report
     */
    private function importRow(string $vendorName, string $approverEmail, array &$report): void
    {
        $match = OrganizationVendorMatcherService::match($this->app, $this->company, $vendorName);
        $organization = $match->organization;
        $lowConfidence = false;

        if ($organization === null && count($match->candidates) > 1) {
            $names = implode(', ', array_map(static fn (Organization $o): string => $o->name, $match->candidates));
            $report['ambiguous'][] = "{$vendorName} (candidates: {$names})";

            return;
        }

        if ($organization === null && count($match->candidates) === 1) {
            // Weaker evidence than an auto-match — see class docblock. Reported separately, not a
            // routine success.
            $organization = $match->candidates[0];
            $lowConfidence = true;
        }

        if ($organization === null) {
            $organization = new CreateOrganizationAction(
                new OrganizationData(
                    company: $this->company,
                    user: $this->company->user,
                    app: $this->app,
                    name: $vendorName,
                ),
            )->execute();

            $this->linkVendorApprover($organization, $vendorName, $approverEmail);
            $report['created']++;
            $report['linked'][] = "{$vendorName} -> {$organization->name} -> {$approverEmail}";

            return;
        }

        if ($this->alreadyLinked($organization, $vendorName, $approverEmail)) {
            $report['unchanged']++;

            return;
        }

        $this->linkVendorApprover($organization, $vendorName, $approverEmail);
        $report['updated']++;
        $entry = "{$vendorName} -> {$organization->name} -> {$approverEmail}";
        $report[$lowConfidence ? 'linked_low_confidence' : 'linked'][] = $entry;
    }

    /** True when this exact vendor name + approver email is already recorded — nothing to write. */
    private function alreadyLinked(Organization $organization, string $vendorName, string $approverEmail): bool
    {
        $currentEmail = (string) $organization->get(OrganizationApproverCustomFieldEnum::APPROVER_EMAIL->value, '');
        $currentVendorName = (string) $organization->get(OrganizationApproverCustomFieldEnum::VENDOR_NAME->value, '');

        // Emails are case-insensitive; a re-exported sheet routinely differs only in casing.
        if (strcasecmp($currentEmail, $approverEmail) !== 0 || $currentVendorName !== $vendorName) {
            return false;
        }

        $linkedEmails = array_map('strtolower', OrganizationApprover::emailsFor($organization));

        return in_array(strtolower($approverEmail), $linkedEmails, true);
    }

    private function linkVendorApprover(Organization $organization, string $vendorName, string $approverEmail): void
    {
        $organization->set(OrganizationApproverCustomFieldEnum::APPROVER_EMAIL->value, $approverEmail);
        $organization->set(OrganizationApproverCustomFieldEnum::VENDOR_NAME->value, $vendorName);
        new LinkApproverEmailToOrganizationAction($organization, $approverEmail)->execute();
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
