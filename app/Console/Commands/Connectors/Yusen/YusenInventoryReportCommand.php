<?php

declare(strict_types=1);

namespace App\Console\Commands\Connectors\Yusen;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Yusen\Jobs\ProcessYusenInventoryBalanceJob;

/**
 * Runs the Yusen discrepancy report for an `Item Balance` XML on a local path.
 *
 * The receiver is the production path; this exists for a file that arrived out of band, and is
 * the seam the planned SFTP poller will call once per downloaded file.
 */
class YusenInventoryReportCommand extends Command
{
    use KanvasJobsTrait;

    protected $signature = 'kanvas:connectors:yusen-inventory-report
                            {app_id : Apps id the inventory belongs to}
                            {company_id : Companies id whose inventory is compared}
                            {path : Absolute path to the Item Balance XML}
                            {--sync : Run inline instead of dispatching to the queue}';

    protected $description = 'Report where a Yusen Item Balance XML disagrees with Kanvas and NetSuite';

    public function handle(): int
    {
        /** @var Apps $app */
        $app = Apps::getById((int) $this->argument('app_id'));
        /** @var Companies $company */
        $company = Companies::getById((int) $this->argument('company_id'));
        $path = (string) ($this->argument('path') ?? '');

        $this->overwriteAppService($app);

        if (! is_readable($path)) {
            $this->error('File is not readable: ' . $path);

            return self::FAILURE;
        }

        $xml = file_get_contents($path);

        if ($xml === false) {
            $this->error('Could not read file: ' . $path);

            return self::FAILURE;
        }

        $job = new ProcessYusenInventoryBalanceJob(
            app: $app,
            company: $company,
            rawXml: $xml,
            fileName: basename($path),
        );

        if ($this->option('sync') !== true) {
            dispatch($job);
            $this->info('Queued Yusen discrepancy report for ' . basename($path));

            return self::SUCCESS;
        }

        $result = $job->handle();
        unset($result['rows']);

        $this->table(
            ['metric', 'value'],
            array_map(
                fn ($key, $value) => [$key, is_scalar($value) ? (string) $value : json_encode($value)],
                array_keys($result),
                array_values($result)
            )
        );

        return self::SUCCESS;
    }
}
