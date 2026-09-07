<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Accounting;

use Baka\Http\SafeUrlFetcher;
use Kanvas\Filesystem\Models\Filesystem;
use Kanvas\Guild\Organizations\Actions\ImportVendorApproversFromRowsAction;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use Override;
use Throwable;

/**
 * Bulk version of add_organization_approver — imports a whole Vendor Name / Approver Email
 * spreadsheet (xlsx/csv) in one call, from a file already attached to this conversation. Shares
 * its matching/linking rules with the scribe:import-vendor-approvers CLI command via
 * ImportVendorApproversFromRowsAction, so both stay in sync.
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
                . 'exactly one plausible candidate (flagged back as low-confidence, since that link is weaker '
                . 'evidence than a normal match). Only a genuine tie between several similarly-plausible '
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
            ->where('id', $filesystem_id)
            ->fromApp($this->app)
            ->fromCompany($this->company)
            ->notDeleted()
            ->first();

        if ($filesystem === null) {
            return [
                'imported' => false,
                'reason' => 'file_not_found',
                'message' => "No filesystem_id {$filesystem_id} for this app/company.",
            ];
        }

        $tempPath = tempnam(sys_get_temp_dir(), 'vendor_approvers_') . '.' . $this->spreadsheetExtension($filesystem->name);

        try {
            file_put_contents($tempPath, SafeUrlFetcher::fetch((string) $filesystem->url));
            $result = ImportVendorApproversFromRowsAction::fromFilePath($this->app, $this->company, $tempPath)->execute();
        } catch (Throwable $e) {
            return [
                'imported' => false,
                'reason' => 'download_or_parse_failed',
                'message' => 'Could not read that file as a spreadsheet: ' . $e->getMessage(),
            ];
        } finally {
            if (file_exists($tempPath)) {
                unlink($tempPath);
            }
        }

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
            'linked_low_confidence' => $result['linked_low_confidence'],
            'ambiguous' => $result['ambiguous'],
            'no_email' => $result['no_email'],
            'next' => 'Report updated/created/unchanged counts plainly. If linked_low_confidence is non-empty, '
                . 'tell the user plainly that those vendors were linked on a single weak match and should be '
                . 'double-checked, listing them by name — do not present them as a routine success. If '
                . 'ambiguous/no_email are non-empty, list those vendors too so the user knows exactly which ones '
                . 'still need manual attention.',
        ];
    }

    /** Never trust the uploaded file's own name for the temp file's extension — allowlist only. */
    private function spreadsheetExtension(?string $originalName): string
    {
        $extension = strtolower(pathinfo((string) $originalName, PATHINFO_EXTENSION));

        return in_array($extension, ['xlsx', 'xls', 'csv', 'tsv'], true) ? $extension : 'xlsx';
    }
}
