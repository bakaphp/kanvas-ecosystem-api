<?php

declare(strict_types=1);

namespace App\Console\Commands\Workflow;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Kanvas\Workflow\Enums\StatusEnum;
use Kanvas\Workflow\Integrations\Actions\PruneIntegrationHistoryAction;

/**
 * Keeps `entity_integration_history` to `workflows.integration_history_retention_days`, skipped
 * runs to `skipped_history_retention_days`.
 */
class PruneIntegrationHistoryCommand extends Command
{
    protected $signature = 'kanvas:workflow-prune-integration-history
        {--minutes=50 : Stop after this long; the next run carries on}
        {--batch=5000 : Rows deleted per statement}';

    protected $description = 'Delete integration history older than the configured retention.';

    public function handle(): int
    {
        $deadline = now()->addMinutes((int) $this->option('minutes'));
        $batch = max(1, (int) $this->option('batch'));
        $skippedStatusId = DB::connection('workflow')->table('status')
            ->where('slug', StatusEnum::SKIPPED->value)
            ->where('apps_id', 0)
            ->value('id');

        $skipped = $skippedStatusId === null ? 0 : new PruneIntegrationHistoryAction(
            cutoff: now()->subDays((int) config('workflows.skipped_history_retention_days')),
            deadline: $deadline,
            onlyStatusId: (int) $skippedStatusId,
            batch: $batch,
        )->execute();

        $older = new PruneIntegrationHistoryAction(
            cutoff: now()->subDays((int) config('workflows.integration_history_retention_days')),
            deadline: $deadline,
            batch: $batch,
        )->execute();

        $this->info(sprintf('Deleted %d skipped runs and %d older runs.', $skipped, $older));

        return self::SUCCESS;
    }
}
