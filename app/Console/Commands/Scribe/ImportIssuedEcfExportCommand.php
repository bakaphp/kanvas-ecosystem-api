<?php

declare(strict_types=1);

namespace App\Console\Commands\Scribe;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Scribe\ElectronicInvoicing\Actions\ImportIssuedEcfExportAction;
use Kanvas\Scribe\ElectronicInvoicing\DataTransferObject\IssuedEcfImportResult;

/**
 * Support-only wrapper over ImportIssuedEcfExportAction for a file nobody can hand the AR agent; the agent tool
 * import_issued_ecf_export is the normal path.
 */
class ImportIssuedEcfExportCommand extends Command
{
    use KanvasJobsTrait;

    protected $signature = 'kanvas-scribe:import-issued-ecf {apps_id} {company_id} {file} {--dry-run}';
    protected $description = 'Imports e-CFs issued in the provider portal (WebPOS issued-documents CSV export) as issued invoices with fiscal rows and journal entries';

    public function handle(): int
    {
        $app = Apps::getById((int) $this->argument('apps_id'));
        $this->overwriteAppService($app);
        $company = Companies::getById((int) $this->argument('company_id'));
        $contents = file_get_contents((string) $this->argument('file'));

        if ($contents === false) {
            $this->error('Could not read ' . $this->argument('file'));

            return self::FAILURE;
        }

        $result = new ImportIssuedEcfExportAction(
            app: $app,
            company: $company,
            contents: $contents,
            user: $company->getAiAgentUserOrFail(),
            dryRun: (bool) $this->option('dry-run'),
        )->execute();
        $report = $result->toArray();

        if ($result->outcome === IssuedEcfImportResult::UNKNOWN_LAYOUT) {
            $this->error('Not the issued-documents export. Header found: ' . implode(' | ', $report['header'] ?? []));

            return self::FAILURE;
        }

        foreach (['created', 'rejected_recorded', 'skipped_existing', 'needs_review', 'failed'] as $bucket) {
            foreach ($report[$bucket] as $row) {
                $this->line(sprintf('%-18s %s  %s', $bucket, $row['encf'], $row['message']));
            }
        }

        foreach ($report['unreadable'] as $line) {
            $this->warn($line);
        }

        $this->info(sprintf(
            '%s%d created, %d rejected recorded, %d skipped, %d need review, %d failed, %d unreadable. Open balance: %s',
            $result->dryRun ? 'DRY RUN. ' : '',
            count($report['created']),
            count($report['rejected_recorded']),
            count($report['skipped_existing']),
            count($report['needs_review']),
            count($report['failed']),
            count($report['unreadable']),
            json_encode($report['open_balance_imported']),
        ));

        return self::SUCCESS;
    }
}
