<?php

declare(strict_types=1);

namespace App\Console\Commands\Workflows;

use Illuminate\Console\Command;
use Kanvas\Workflow\Services\WorkflowActionCatalog;

class KanvasWorkflowSynActionCommand extends Command
{
    protected $signature = 'kanvas:workflow-sync-actions';

    protected $description = 'Sync discovered #[WorkflowAction] classes into the actions catalog.';

    public function handle(WorkflowActionCatalog $catalog): void
    {
        $this->info('Syncing Workflow Actions...');

        $changes = $catalog->sync();

        $this->report('created', $changes['created']);
        $this->report('updated', $changes['updated']);

        $this->info('Syncing Workflow Actions Done!');
    }

    /**
     * @param list<string> $names
     */
    private function report(string $label, array $names): void
    {
        if ($names === []) {
            $this->info(sprintf('No actions were %s.', $label));

            return;
        }

        $this->info(sprintf('The following actions were %s:', $label));

        foreach ($names as $name) {
            $this->line("- {$name}");
        }
    }
}
