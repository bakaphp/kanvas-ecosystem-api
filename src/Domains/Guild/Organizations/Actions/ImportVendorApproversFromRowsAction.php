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

/**
 * Sets ap_approver_email/ap_approver_vendor_name on each vendor Organization and links a real
 * Kanvas User as its OrganizationApprover, from raw spreadsheet rows (Vendor Name -> Approver
 * Email). Shared by ImportVendorApproversCommand (CLI, a file path) and ImportVendorApproversTool
 * (agent-callable, an uploaded Filesystem row) so both stay in sync with the same matching rules.
 *
 * A vendor with no existing Organization match gets one created on the fly. A vendor with a single
 * plausible-but-not-certain candidate is linked to it anyway — the real misfiling risk is picking
 * between SEVERAL similarly-plausible candidates, not accepting the only one there is — so only a
 * genuine multi-candidate tie is skipped for manual resolution. Idempotent — a vendor whose
 * approver is already exactly what the sheet says is left untouched and reported separately from
 * ones actually changed, so re-running with the same or an updated sheet only touches what's new.
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
     * @return array{updated: int, created: int, unchanged: int, resolved_single_candidate: list<string>, ambiguous: list<string>, no_email: list<string>}|array{error: string}
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
        $unchanged = 0;
        $resolvedSingleCandidate = [];
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
            $organization = $match->organization;

            if (! $match->isMatched() && $match->candidates !== []) {
                if (count($match->candidates) > 1) {
                    $names = implode(', ', array_map(static fn (Organization $o): string => $o->name, $match->candidates));
                    $ambiguous[] = "{$vendorName} (candidates: {$names})";

                    continue;
                }

                // Exactly one plausible candidate — not confident enough to auto-select over a
                // real runner-up, but there is no runner-up to be wrong about here.
                $organization = $match->candidates[0];
                $resolvedSingleCandidate[] = "{$vendorName} -> {$organization->name}";
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
                $created++;

                continue;
            }

            if ($this->alreadyLinked($organization, $vendorName, $approverEmail)) {
                $unchanged++;

                continue;
            }

            $this->linkVendorApprover($organization, $vendorName, $approverEmail);
            $updated++;
        }

        return [
            'updated' => $updated,
            'created' => $created,
            'unchanged' => $unchanged,
            'resolved_single_candidate' => $resolvedSingleCandidate,
            'ambiguous' => $ambiguous,
            'no_email' => $noEmail,
        ];
    }

    /** True when this exact vendor name + approver email is already recorded — nothing to write. */
    private function alreadyLinked(Organization $organization, string $vendorName, string $approverEmail): bool
    {
        $currentEmail = (string) $organization->get(OrganizationApproverCustomFieldEnum::APPROVER_EMAIL->value, '');
        $currentVendorName = (string) $organization->get(OrganizationApproverCustomFieldEnum::VENDOR_NAME->value, '');

        if ($currentEmail !== $approverEmail || $currentVendorName !== $vendorName) {
            return false;
        }

        return in_array($approverEmail, OrganizationApprover::emailsFor($organization), true);
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
