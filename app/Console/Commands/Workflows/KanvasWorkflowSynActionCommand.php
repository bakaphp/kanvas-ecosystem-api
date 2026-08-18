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
            // Deliberately not filtered by is_deleted: a handler that was soft-deleted and then
            // re-tagged should be revived, and `model_name` is unique, so inserting instead would
            // violate the constraint.
            $record = Action::firstOrNew(['model_name' => $entry['class']]);

            // The attribute is the source of truth for everything here, so the metadata is written on
            // every run — descriptions are the point of the catalog and would otherwise only ever land
            // on rows created after the attribute was written. Rules reference actions by id, so
            // rewriting the name cannot detach an existing rule.
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
