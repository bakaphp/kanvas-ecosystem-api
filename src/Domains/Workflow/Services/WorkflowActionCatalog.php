<?php

declare(strict_types=1);

namespace Kanvas\Workflow\Services;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Kanvas\Workflow\Models\Integrations;
use Kanvas\Workflow\Models\ReceiverWebhook;
use Kanvas\Workflow\Rules\Models\Action;
use Kanvas\Workflow\Rules\Models\RuleWorkflowAction;

/**
 * The only writer of the `actions` catalog. The deploy-time sync and anything that needs one entry
 * to exist right now (provisioning a receiver before the next deploy has synced) go through here, so
 * a row is always what discovery says it is — including the disambiguated name, which only the full
 * discovery pass can compute.
 */
class WorkflowActionCatalog
{
    public function __construct(
        protected WorkflowActionDiscoveryService $discovery = new WorkflowActionDiscoveryService()
    ) {
    }

    /**
     * @return array{created: list<string>, updated: list<string>}
     */
    public function sync(): array
    {
        $changes = ['created' => [], 'updated' => []];

        foreach ($this->discovery->discover() as $entry) {
            $change = $this->persist($entry)['change'];

            if ($change !== null) {
                $changes[$change][] = $entry['name'];
            }
        }

        return $changes;
    }

    /**
     * @param class-string $class
     */
    public function register(string $class): Action
    {
        foreach ($this->discovery->discover() as $entry) {
            if ($entry['class'] === $class) {
                return $this->persist($entry)['action'];
            }
        }

        throw new InvalidArgumentException($class . ' is not a discoverable #[WorkflowAction] class.');
    }

    /**
     * @return array{action: Action, change: 'created'|'updated'|null}
     */
    protected function persist(array $entry): array
    {
        $record = Action::firstOrNew(['model_name' => $entry['class']]);
        $legacy = $entry['legacy_names'] === []
            ? collect()
            : Action::query()->notDeleted()->whereIn('model_name', $entry['legacy_names'])->get();

        // A class that moved keeps its row, so every receiver and rule on it keeps working the moment
        // the deploy syncs — on 1.x a migration would wait for a hand that may never come.
        if (! $record->exists && $legacy->isNotEmpty()) {
            $record = $legacy->shift();
            $record->model_name = $entry['class'];
        }

        $record->fill([
            'name' => $entry['name'],
            'kind' => $entry['kind'],
            'description' => $entry['description'],
            'integration' => $entry['integration'],
            'requires_config' => $entry['requires_config'],
            'params' => $entry['params'],
            'required_params' => $entry['required_params'],
        ]);

        $change = match (true) {
            ! $record->exists => 'created',
            $record->isDirty() => 'updated',
            default => null,
        };

        if ($change !== null) {
            $record->save();
        }

        foreach ($legacy as $duplicate) {
            $this->absorb($duplicate, $record);
        }

        return ['action' => $record, 'change' => $change];
    }

    /**
     * A sync that ran before the class declared its old name left that row beside the new one, with
     * every receiver and rule still on the old. They move to the surviving row and the old one goes.
     */
    protected function absorb(Action $old, Action $into): void
    {
        DB::connection('workflow')->transaction(function () use ($old, $into): void {
            ReceiverWebhook::query()->where('action_id', $old->getId())->update(['action_id' => $into->getId()]);
            RuleWorkflowAction::query()->where('actions_id', $old->getId())->update(['actions_id' => $into->getId()]);
            Integrations::query()->where('actions_id', $old->getId())->update(['actions_id' => $into->getId()]);
            $old->delete();
        });
    }
}
