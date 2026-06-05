<?php

declare(strict_types=1);

namespace App\Console\Commands\Workflows;

use Illuminate\Console\Command;
use Kanvas\Workflow\Rules\Models\Action;
use Kanvas\Workflow\Services\WorkflowActionDiscoveryService;

class KanvasWorkflowSynActionCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'kanvas:workflow-sync-actions';

    public function handle(WorkflowActionDiscoveryService $discovery): void
    {
        $this->info('Syncing Workflow Actions...');

        $createdActions = [];

        foreach ($discovery->discover() as $entry) {
            $record = Action::firstOrCreate(
                ['model_name' => $entry['class']],
                ['name' => $entry['name']],
            );

            if ($record->wasRecentlyCreated) {
                $createdActions[] = $record->name;
            }
        }

        if (! empty($createdActions)) {
            $this->info('The following actions were created:');
            foreach ($createdActions as $actionName) {
                $this->line("- {$actionName}");
            }
        } else {
            $this->info('No new actions were created.');
        }

        $this->info('Syncing Workflow Actions Done!');
    }
}
