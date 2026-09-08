<?php

declare(strict_types=1);

namespace Kanvas\Guild\Organizations\Actions;

use Baka\Support\Str;
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
 * Sets ap_approver_email/ap_approver_vendor_name on each vendor Organization and links a real Kanvas
 * User as its OrganizationApprover, from raw Vendor Name -> Approver Email spreadsheet rows. Shared
 * by ImportVendorApproversCommand (CLI, a file path) and ImportVendorApproversTool (agent-callable,
 * an uploaded Filesystem row) so both stay in sync.
 *
 * A vendor with exactly one candidate is linked to it even though OrganizationVendorMatcherService
 * declined to auto-match — a lone candidate reaches here specifically because its score sits below
 * that bar — so those links are reported apart, in `linked_low_confidence`, rather than as routine
 * successes.
 */
class ImportVendorApproversFromRowsAction
{
    /**
     * @var array{updated: int, created: int, unchanged: int, linked: list<string>, linked_low_confidence: list<string>, ambiguous: list<string>, invalid_email: list<string>, no_email: list<string>}
     */
    private array $report = [
        'updated' => 0,
        'created' => 0,
        'unchanged' => 0,
        'linked' => [],
        'linked_low_confidence' => [],
        'ambiguous' => [],
        'invalid_email' => [],
        'no_email' => [],
    ];

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
     * @return array{updated: int, created: int, unchanged: int, linked: list<string>, linked_low_confidence: list<string>, ambiguous: list<string>, invalid_email: list<string>, no_email: list<string>}|array{error: string}
     */
    public function execute(): array
    {
        $header = $this->findHeaderRow();

        if ($header === null) {
            return ['error' => 'Could not find a header row containing "Vendor Name" and "Approver Email" columns.'];
        }

        [$headerIndex, $vendorColumn, $emailColumn] = $header;

        foreach (array_slice($this->rows, $headerIndex + 1) as $row) {
            $vendorName = Str::trimToNull((string) ($row[$vendorColumn] ?? ''));
            $approverEmail = Str::trimToNull((string) ($row[$emailColumn] ?? ''));

            if ($vendorName === null) {
                continue;
            }

            if ($approverEmail === null) {
                $this->report['no_email'][] = $vendorName;

                continue;
            }

            if (! filter_var($approverEmail, FILTER_VALIDATE_EMAIL)) {
                $this->report['invalid_email'][] = "{$vendorName} ({$approverEmail})";

                continue;
            }

            $this->importRow($vendorName, $approverEmail);
        }

        return $this->report;
    }

    private function importRow(string $vendorName, string $approverEmail): void
    {
        $match = OrganizationVendorMatcherService::match($this->app, $this->company, $vendorName);
        $organization = $match->organization;
        $lowConfidence = false;

        if ($organization === null) {
            if (count($match->candidates) > 1) {
                $names = implode(', ', array_map(static fn (Organization $o): string => $o->name, $match->candidates));
                $this->report['ambiguous'][] = "{$vendorName} (candidates: {$names})";

                return;
            }

            // Weaker evidence than an auto-match — see the class docblock.
            $lowConfidence = $match->candidates !== [];
            $organization = $match->candidates[0] ?? null;
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
            $this->report['created']++;
            $this->report['linked'][] = "{$vendorName} -> {$organization->name} -> {$approverEmail}";

            return;
        }

        if ($this->alreadyLinked($organization, $vendorName, $approverEmail)) {
            $this->report['unchanged']++;

            return;
        }

        $this->linkVendorApprover($organization, $vendorName, $approverEmail);
        $this->report['updated']++;
        $key = $lowConfidence ? 'linked_low_confidence' : 'linked';
        $this->report[$key][] = "{$vendorName} -> {$organization->name} -> {$approverEmail}";
    }

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
     * @return array{0: int, 1: int, 2: int}|null
     */
    private function findHeaderRow(): ?array
    {
        foreach ($this->rows as $index => $row) {
            $vendorColumn = array_search('Vendor Name', $row, true);
            $emailColumn = array_search('Approver Email', $row, true);

            if ($vendorColumn !== false && $emailColumn !== false) {
                return [$index, $vendorColumn, $emailColumn];
            }
        }

        return null;
    }
}
