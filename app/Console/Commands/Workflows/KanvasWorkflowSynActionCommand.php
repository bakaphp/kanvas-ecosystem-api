<?php

declare(strict_types=1);

namespace App\Console\Commands\Workflows;

use Illuminate\Console\Command;
use Kanvas\Workflow\Rules\Models\Action;
use Kanvas\Workflow\Services\WorkflowActionDiscoveryService;

class KanvasWorkflowSynActionCommand extends Command
{
    protected $signature = 'kanvas:workflow-sync-actions';

    protected $description = 'Sync discovered #[WorkflowAction] classes into the actions catalog.';

    public function handle(WorkflowActionDiscoveryService $discovery): void
    {
        $this->info('Syncing Workflow Actions...');

        $created = [];
        $updated = [];

        foreach ($discovery->discover() as $entry) {
            $record = Action::firstOrNew(['model_name' => $entry['class']]);
            $record->fill([
                'name' => $entry['name'],
                'kind' => $entry['kind'],
                'description' => $entry['description'],
                'integration' => $entry['integration'],
                'requires_config' => $entry['requires_config'],
                'params' => $entry['params'],
                'required_params' => $entry['required_params'],
            ]);

            if (! $record->exists) {
                $record->save();
                $created[] = $entry['name'];

                continue;
            }

            if ($record->isDirty()) {
                $record->save();
                $updated[] = $entry['name'];
            }
        }

        $this->report('created', $created);
        $this->report('updated', $updated);

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
