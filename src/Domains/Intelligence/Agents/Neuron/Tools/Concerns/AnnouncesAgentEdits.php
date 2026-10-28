<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Concerns;

use Illuminate\Database\Eloquent\Model;
use Kanvas\Workflow\Enums\WorkflowEnum;
use Throwable;

/**
 * Who made an edit cannot be read back from the acting user — an agent's user is shared across agents
 * and is often a person's own login — so a tool that edits a record says so itself. It fires
 * AGENT_UPDATED, never UPDATED, so a rule meant only for agent edits (the Salesforce sync approval on
 * People) stays off the mutation path. Rules are per system module, so the same event serves any model.
 */
trait AnnouncesAgentEdits
{
    protected function announceAgentEdit(Model $entity): void
    {
        // A model without workflows can have no rule listening, so there is nothing to tell.
        if (! method_exists($entity, 'fireWorkflowAfterCommit')) {
            return;
        }

        try {
            $entity->fireWorkflowAfterCommit(WorkflowEnum::AGENT_UPDATED->value, ['app' => $entity->app]);
        } catch (Throwable $e) {
            // The edit is saved; a missed announcement only delays what follows, it must not fail the tool.
            report($e);
        }
    }
}
