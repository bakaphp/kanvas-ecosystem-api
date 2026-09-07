<?php

declare(strict_types=1);

namespace Kanvas\Guild\Organizations\Actions;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Guild\Organizations\DataTransferObject\Organization as OrganizationData;
use Kanvas\Guild\Organizations\Models\Organization;
use Kanvas\Guild\Organizations\Services\OrganizationVendorMatcherService;
use Kanvas\Scribe\Approvals\Enums\OrganizationApproverCustomFieldEnum;

/**
 * Sets ap_approver_email/ap_approver_vendor_name on each vendor Organization and links a real
 * Kanvas User as its OrganizationApprover, from raw spreadsheet rows (Vendor Name -> Approver
 * Email). Shared by ImportVendorApproversCommand (CLI, a file path) and ImportVendorApproversTool
 * (agent-callable, an uploaded Filesystem row) so both stay in sync with the same matching rules.
 *
 * A vendor with no existing Organization match gets one created on the fly. A vendor with SEVERAL
 * possible matches is skipped for manual resolution rather than auto-picked, since guessing wrong
 * could silently misfile it against the wrong existing Organization. Idempotent — safe to run
 * again with an updated sheet.
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

    /**
     * @return array{updated: int, created: int, ambiguous: list<string>, no_email: list<string>}|array{error: string}
     */
    public function execute(): array
    {
        $header = $this->findHeaderRow($this->rows);

        if ($header === null) {
            return ['error' => 'Could not find a header row containing "Vendor Name" and "Approver Email" columns.'];
        }

        [$headerIndex, $vendorColumn, $emailColumn] = $header;

        $updated = 0;
        $created = 0;
        $ambiguous = [];
        $noEmail = [];

        foreach (array_slice($this->rows, $headerIndex + 1) as $row) {
            $vendorName = trim((string) ($row[$vendorColumn] ?? ''));
            $approverEmail = trim((string) ($row[$emailColumn] ?? ''));

            if ($vendorName === '') {
                continue;
            }

            if ($approverEmail === '') {
                $noEmail[] = $vendorName;

                continue;
            }

            $match = OrganizationVendorMatcherService::match($this->app, $this->company, $vendorName);

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
                    company: $this->company,
                    user: $this->company->user,
                    app: $this->app,
                    name: $vendorName,
                ),
            )->execute();

            $this->linkVendorApprover($organization, $vendorName, $approverEmail);
            $created++;
        }

        return [
            'updated' => $updated,
            'created' => $created,
            'ambiguous' => $ambiguous,
            'no_email' => $noEmail,
        ];
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
