<?php

declare(strict_types=1);

namespace Kanvas\Workflow\Services;

use InvalidArgumentException;
use Kanvas\Workflow\Rules\Models\Action;

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

        return ['action' => $record, 'change' => $change];
    }
}
