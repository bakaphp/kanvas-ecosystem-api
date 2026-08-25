<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Yusen\Jobs;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Yusen\Actions\BuildYusenDiscrepancyReportAction;
use Kanvas\Connectors\Yusen\Actions\SendYusenDiscrepancyReportAction;
use Kanvas\Connectors\Yusen\Services\InventoryBalanceXmlParser;
use Kanvas\Filesystem\Models\Filesystem;
use Kanvas\Filesystem\Services\FilesystemServices;
use Throwable;

/**
 * Parses one Yusen `Item Balance` delivery and reports where it disagrees with Kanvas and NetSuite.
 *
 * Runs off the receiver rather than inside it: a full catalog balance takes far longer to parse
 * and reconcile than the window Yusen's uploader will wait for an ack.
 */
class ProcessYusenInventoryBalanceJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use KanvasJobsTrait;
    use Queueable;
    use SerializesModels;

    /**
     * Discrepancy rows kept in the returned report. A full-catalog disagreement would otherwise
     * turn `receiver_webhook_calls.results` into a multi-megabyte JSON blob; `rows_omitted` says
     * how many were dropped so the cap is never silent.
     */
    private const int STORED_ROW_LIMIT = 200;

    public function __construct(
        public readonly Apps $app,
        public readonly Companies $company,
        public readonly ?int $filesystemId = null,
        public readonly ?string $rawXml = null,
        public readonly ?string $fileName = null,
    ) {
    }

    public function handle(): array
    {
        $this->overwriteAppService($this->app);

        $parser = new InventoryBalanceXmlParser();
        $filesystem = $this->resolveFilesystem();

        $balance = $filesystem !== null
            ? $parser->parseFile(
                new FilesystemServices($this->app, $this->company)->getFileLocalPath($filesystem)
            )
            : $parser->parseString((string) $this->rawXml);

        $report = new BuildYusenDiscrepancyReportAction(
            $this->app,
            $this->company,
            $balance,
        )->execute();

        if ($report['multi_record_items'] > 0) {
            // Guards the "SKU/Quantity is per-record, so lot rows sum" reading of Yusen's format.
            // If they ever repeat an item-level total per lot row instead, this is the first
            // place it shows up — before the numbers quietly go wrong.
            Log::info('Yusen.InventoryBalance — file contains items with multiple inventory records', [
                'multi_record_items' => $report['multi_record_items'],
                'external_id' => $report['external_id'],
                'companies_id' => $this->company->getId(),
            ]);
        }

        $report = ['file_name' => $this->fileName] + $report;
        $report['notified_users'] = $this->mail($report);

        return $this->trimForStorage($report);
    }

    /**
     * A broken mail template or a bad recipient must not lose the report that was already
     * computed — the failure is recorded on the result instead of failing the job.
     *
     * @param array<string, mixed> $report
     * @return array<int, int>
     */
    private function mail(array $report): array
    {
        try {
            return new SendYusenDiscrepancyReportAction($this->app, $this->company, $report)->execute();
        } catch (Throwable $e) {
            report($e);

            return [];
        }
    }

    /**
     * @param array<string, mixed> $report
     * @return array<string, mixed>
     */
    private function trimForStorage(array $report): array
    {
        $rows = $report['rows'];
        $dropped = count($rows) - self::STORED_ROW_LIMIT;

        if ($dropped <= 0) {
            return $report;
        }

        $report['rows'] = array_slice($rows, 0, self::STORED_ROW_LIMIT);
        $report['rows_omitted'] = $dropped;

        return $report;
    }

    private function resolveFilesystem(): ?Filesystem
    {
        if ($this->filesystemId === null) {
            return null;
        }

        return Filesystem::query()
            ->where('id', $this->filesystemId)
            ->where('apps_id', $this->app->getId())
            ->first();
    }
}
