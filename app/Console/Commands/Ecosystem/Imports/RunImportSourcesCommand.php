<?php

declare(strict_types=1);

namespace App\Console\Commands\Ecosystem\Imports;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Kanvas\Imports\Actions\RunImportSourceAction;
use Kanvas\Imports\Models\ImportSource;

/**
 * Runs every 15 minutes and queues each scheduled import whose time has come. Each source carries
 * its own cron + timezone (or inherits its connection's), so different apps and file providers run
 * at different times with no code change. Dispatch only: the job binds its own app.
 */
class RunImportSourcesCommand extends Command
{
    use KanvasJobsTrait;

    protected $signature = 'kanvas:imports:run-sources
                            {--source= : Only this import source id}
                            {--dry-run : Download, merge and map, then print; nothing is unpublished or imported (needs --source)}
                            {--force : Queue it even if it is not due}';

    protected $description = 'Queue the scheduled imports that are due';

    public function handle(): int
    {
        if ($this->option('dry-run')) {
            return $this->dryRun();
        }

        $query = ImportSource::query()->notDeleted()->with('importConnection');

        if ($this->option('source')) {
            $query->where('id', (int) $this->option('source'));
        } else {
            $query->where('is_active', true);
        }

        $now = now();
        $queued = 0;

        $query->chunkById(100, function (Collection $sources) use ($now, &$queued): void {
            foreach ($sources as $source) {
                if (! $this->option('force') && ! $source->isDue($now)) {
                    continue;
                }

                $source->queueRun();
                $this->line(sprintf('Queued #%d %s', $source->getId(), $source->name));
                $queued++;
            }
        });

        $this->info($queued . ' import(s) queued.');

        return self::SUCCESS;
    }

    private function dryRun(): int
    {
        if (! $this->option('source')) {
            $this->error('--dry-run needs --source=<id>');

            return self::FAILURE;
        }

        $source = ImportSource::getById((int) $this->option('source'));
        $this->overwriteAppService($source->app);

        $result = new RunImportSourceAction($source, dryRun: true)->execute();

        $this->info($result->status->value . ': ' . $result->message);
        $this->table(['File', 'Matched', 'Downloaded'], array_map(
            fn (array $file) => [$file['pattern'], $file['matched'] ?? '—', $file['downloaded'] ? 'yes' : 'no'],
            $result->files
        ));
        $this->line(sprintf('Rows: %d, skipped rows: %d', $result->rows, $result->skippedRows));

        foreach ($result->sample as $record) {
            $this->line((string) json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        }

        return self::SUCCESS;
    }
}
