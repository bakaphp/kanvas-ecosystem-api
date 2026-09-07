<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Accounting;

use Baka\Http\SafeUrlFetcher;
use Kanvas\Filesystem\Models\Filesystem;
use Kanvas\Guild\Organizations\Actions\ImportVendorApproversFromRowsAction;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Support\Excel\NullExcelImport;
use Maatwebsite\Excel\Facades\Excel;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use Override;
use Throwable;

/**
 * Bulk version of add_organization_approver — imports a whole Vendor Name / Approver Email
 * spreadsheet (xlsx/csv) in one call, from a file already attached to this conversation. Shares
 * its matching/linking rules with the scribe:import-vendor-approvers CLI command via
 * ImportVendorApproversFromRowsAction, so both stay in sync — including linking a vendor to its
 * one plausible existing match rather than leaving it for manual resolution, and skipping a vendor
 * whose approver is already exactly what the sheet says.
 */
#[AgentTool(name: 'Import Vendor Approvers', category: 'accounting')]
class ImportVendorApproversTool extends Tool
{
    use HasKanvasContext;

    public function __construct()
    {
        parent::__construct(
            name: 'import_vendor_approvers',
            description: 'Imports a whole vendor/approver spreadsheet (xlsx/csv) in one call — sets each vendor '
                . 'Organization\'s approver email and links a real Kanvas User as its approver, creating the '
                . 'Organization when nothing matches and linking to the closest existing one when there is '
                . 'exactly one plausible candidate. Only a genuine tie between several similarly-plausible '
                . 'candidates is left for manual resolution. Re-running with the same or an updated sheet only '
                . 'touches vendors whose recorded approver actually differs. The sheet needs a "Vendor Name" '
                . 'column and an "Approver Email" column (any other columns, e.g. "Approver Name", are ignored) '
                . '— never guess column names, the sheet must have these exact headers somewhere in it. Use '
                . 'this when the user attaches an updated vendor/approver list and asks to load it.',
        );
    }

    /**
     * @return array<int, ToolProperty>
     */
    #[Override]
    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'filesystem_id',
                type: PropertyType::INTEGER,
                description: 'The filesystem_id of the uploaded spreadsheet — from the '
                    . '`[Attached file on this message — filesystem_id: ...]` marker. Never guess it.',
                required: true,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(int $filesystem_id): array
    {
        $filesystem = Filesystem::query()
            ->fromApp($this->app)
            ->where('companies_id', $this->company->getId())
            ->where('id', $filesystem_id)
            ->first();

        if ($filesystem === null) {
            return [
                'imported' => false,
                'reason' => 'file_not_found',
                'message' => "No filesystem_id {$filesystem_id} for this app/company.",
            ];
        }

        try {
            $bytes = SafeUrlFetcher::fetch((string) $filesystem->url);
        } catch (Throwable $e) {
            return [
                'imported' => false,
                'reason' => 'download_failed',
                'message' => 'Could not download that file: ' . $e->getMessage(),
            ];
        }

        $extension = pathinfo((string) $filesystem->name, PATHINFO_EXTENSION) ?: 'xlsx';
        $tmpPath = sys_get_temp_dir() . '/vendor_approvers_' . uniqid('', true) . '.' . $extension;
        file_put_contents($tmpPath, $bytes);

        try {
            $rows = Excel::toArray(new NullExcelImport(), $tmpPath)[0] ?? [];
        } catch (Throwable $e) {
            return [
                'imported' => false,
                'reason' => 'parse_failed',
                'message' => 'Could not read that file as a spreadsheet: ' . $e->getMessage(),
            ];
        } finally {
            @unlink($tmpPath);
        }

        $result = new ImportVendorApproversFromRowsAction($this->app, $this->company, $rows)->execute();

        if (isset($result['error'])) {
            return [
                'imported' => false,
                'reason' => 'header_not_found',
                'message' => $result['error'],
            ];
        }

        return [
            'imported' => true,
            'updated' => $result['updated'],
            'created' => $result['created'],
            'unchanged' => $result['unchanged'],
            'resolved_single_candidate' => $result['resolved_single_candidate'],
            'ambiguous' => $result['ambiguous'],
            'no_email' => $result['no_email'],
            'next' => 'Report updated/created/unchanged counts plainly. If resolved_single_candidate is '
                . 'non-empty, mention those vendors were linked to their closest existing match. If '
                . 'ambiguous/no_email are non-empty, list those vendors by name so the user knows exactly which '
                . 'ones still need manual attention.',
        ];
    }
}
