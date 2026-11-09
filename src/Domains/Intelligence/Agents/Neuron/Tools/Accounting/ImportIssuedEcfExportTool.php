<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Accounting;

use Kanvas\Filesystem\Services\FileContentsReaderService;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Contracts\RequiresSystemAgent;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\ResolvesFilesystemForTool;
use Kanvas\Intelligence\Tools\Traits\ReportsToolOutcome;
use Kanvas\Scribe\ElectronicInvoicing\Actions\ImportIssuedEcfExportAction;
use Kanvas\Scribe\ElectronicInvoicing\DataTransferObject\IssuedEcfImportResult;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\TrackByInputs;
use Override;
use Throwable;

#[AgentTool(name: 'Import Issued e-CF Export', category: 'accounting')]
class ImportIssuedEcfExportTool extends Tool implements RequiresSystemAgent
{
    use HasKanvasContext;
    use ReportsToolOutcome;
    use ResolvesFilesystemForTool;
    use TrackByInputs;

    protected string $name = 'import_issued_ecf_export';

    protected ?string $description = 'Brings e-CFs the company issued in the provider\'s own portal (WebPOS) before or outside '
        . 'Kanvas onto the books, from the portal\'s issued-documents CSV export: each row becomes an issued, unpaid '
        . 'invoice with its fiscal number, the authority\'s status and its journal entry in the invoice\'s own period; '
        . 'a rejected number is recorded as voided so it is never reused. Customers are matched by RNC, then by name, '
        . 'else created. A number already on the books is skipped, so the same export can be fed again. Never retype '
        . 'rows yourself: hand the file over. Use dry_run first and show the person what would happen.';

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
                description: 'The export file from an `[Attached file...]` marker or download_attachment. Pass this or file_url.',
                required: false,
            ),
            new ToolProperty(
                name: 'file_url',
                type: PropertyType::STRING,
                description: 'A link to the export file, only when you have no filesystem_id.',
                required: false,
            ),
            new ToolProperty(
                name: 'dry_run',
                type: PropertyType::BOOLEAN,
                description: 'True reads the file and reports what each row would do without writing anything. Do this first.',
                required: false,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(?int $filesystem_id = null, ?string $file_url = null, ?bool $dry_run = null): array
    {
        $file = $this->resolveOrUploadTenantFile($filesystem_id, $file_url);
        if (is_array($file)) {
            return $this->invalidArgs($file['message'], ['imported' => false, 'reason' => $file['reason']]);
        }

        try {
            $result = new ImportIssuedEcfExportAction(
                app: $this->app,
                company: $this->company,
                contents: app(FileContentsReaderService::class)->read($file),
                user: $this->user,
                dryRun: $dry_run ?? false,
            )->execute();
        } catch (Throwable $e) {
            report($e);

            return $this->failed('The export could not be read: ' . $e->getMessage(), ['imported' => false]);
        }

        $payload = ['imported' => ! $result->dryRun && $result->outcome === IssuedEcfImportResult::IMPORTED, ...$result->toArray()];

        return match ($result->outcome) {
            IssuedEcfImportResult::IMPORTED => $this->ok(
                $payload,
                $result->dryRun
                    ? 'Nothing was written. Show the person what each row would do, name the customers that would be created '
                        . 'and the rows that need review, then call again without dry_run when they agree.'
                    : 'Report the invoices created with their e-NCF and total, the rejected numbers recorded, what was skipped '
                        . 'as already on the books, and every needs_review row with its reason; those need a person before '
                        . 'they can be imported. Payments are not in the export: say the imported balance is open.',
            ),
            IssuedEcfImportResult::NOTHING_TO_IMPORT => $this->noop($payload, 'The file had the right columns but no readable document rows.'),
            IssuedEcfImportResult::UNKNOWN_LAYOUT => $this->invalidArgs(
                'This file is not the provider\'s issued-documents export.',
                $payload,
                'Nothing was imported. The header the file carries is in the result: tell the person which export this tool reads and ask for that one.',
            ),
        };
    }
}
