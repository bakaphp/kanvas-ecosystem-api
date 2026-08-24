<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Observers;

use Kanvas\Intelligence\AgentRuntime\Providers\AgentRuntimeProviderFactory;
use Kanvas\Intelligence\Agents\Actions\ReconcileAgentKanvasModulesAction;
use Kanvas\Intelligence\Agents\Actions\RecordAgentVersionAction;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentDeployment;
use Throwable;

class AgentObserver
{
    public function deleting(Agent $agent): void
    {
        // Dispatch container termination for every active deployment before CascadeSoftDeletes
        // flips is_deleted — otherwise the containers keep running on the machine indefinitely.
        foreach ($agent->deployments()->where('is_deleted', 0)->get() as $deployment) {
            if ($deployment->status === 'terminated') {
                continue;
            }

            try {
                AgentRuntimeProviderFactory::forDeployment($deployment)
                    ->dispatchTermination($deployment);
            } catch (Throwable $e) {
                report($e);
            }
        }
    }

    /** `updating`, not `updated`: after the write the wording being replaced is already gone. */
    public function updating(Agent $agent): void
    {
        $replaced = [];

        foreach (Agent::PROMPT_FIELDS as $field) {
            if ($agent->isDirty($field)) {
                $replaced[$field] = $agent->getOriginal($field);
            }
        }

        if ($replaced === []) {
            return;
        }

        try {
            $agent->lastRecordedVersion = new RecordAgentVersionAction(
                $agent,
                $replaced,
                $agent->versionChangeReason ?? $this->describeChange($replaced),
                $agent->versionEditedByUserId,
            )->execute();
        } catch (Throwable $e) {
            report($e);
        }

        $agent->versionChangeReason = null;
        $agent->versionEditedByUserId = null;
    }

    /**
     * @param array<string, mixed> $replaced
     */
    private function describeChange(array $replaced): string
    {
        return 'Changed ' . implode(', ', array_keys($replaced));
    }

    public function saving(Agent $agent): void
    {
        $this->syncLegacyRole($agent);
    }

    public function updated(Agent $agent): void
    {
        if (! $agent->wasChanged(Agent::PROMPT_FIELDS)) {
            return;
        }

        $deployments = $agent->deployments()
            ->where('status', 'running')
            ->where('is_deleted', 0)
            ->get();

        /** @var AgentDeployment $deployment */
        foreach ($deployments as $deployment) {
            try {
                AgentRuntimeProviderFactory::forDeployment($deployment)
                    ->dispatchWorkspaceUpdate($deployment);
            } catch (Throwable $e) {
                report($e);
            }
        }
    }

    public function saved(Agent $agent): void
    {
        $agent->clearLightHouseCache(withKanvasConfiguration: false);
    }

    public function created(Agent $agent): void
    {
        $this->appendIdToSlug($agent);
        $this->reconcileKanvasModules($agent);
    }

    /**
     * SlugTrait derives the slug from the name during `creating`, before the
     * auto-increment id exists — so two agents sharing a name collide on slug.
     * The id is only known post-insert, so append it here to keep slugs unique.
     */
    private function appendIdToSlug(Agent $agent): void
    {
        $suffix = '-' . $agent->id;

        if (str_ends_with($agent->slug, $suffix)) {
            return;
        }

        $agent->slug = $agent->slug . $suffix;
        $agent->save();
    }

    private function syncLegacyRole(Agent $agent): void
    {
        $map = [
            'soul' => 'background',
            'instructions' => 'steps',
            'output_format' => 'output',
        ];

        $dirty = array_filter(
            array_keys($map),
            fn (string $field): bool => $agent->isDirty($field),
        );

        if ($dirty === []) {
            return;
        }

        $role = is_array($agent->role) ? $agent->role : [];

        foreach ($dirty as $field) {
            $role[$map[$field]] = $agent->{$field};
        }

        $agent->role = $role;
    }

    private function reconcileKanvasModules(Agent $agent): void
    {
        // Must not block agent save — a missed subscription is recoverable.
        try {
            new ReconcileAgentKanvasModulesAction($agent)->execute();
        } catch (Throwable $e) {
            report($e);
        }
    }
}
