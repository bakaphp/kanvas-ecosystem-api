<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Actions;

use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentVersion;

/**
 * Snapshot an agent's wording as it stands BEFORE an edit, so a bad one is a copy back.
 *
 * Called from the observer rather than from each write path on purpose. An agent's prompt is edited
 * from the admin UI, from GraphQL, from an agent retuning a teammate, and from a console command —
 * and a history that only records the path someone remembered to instrument is worse than none,
 * because it reads as complete.
 */
class RecordAgentVersionAction
{
    /**
     * @param array<string, mixed> $previousWording the values being replaced, keyed by column
     */
    public function __construct(
        private readonly Agent $agent,
        private readonly array $previousWording,
        private readonly ?string $reason = null,
        private readonly ?int $editedByUserId = null,
    ) {
    }

    public function execute(): AgentVersion
    {
        AgentVersion::query()
            ->where('agent_id', $this->agent->getId())
            ->where('is_active', true)
            ->update(['is_active' => false]);

        return AgentVersion::create([
            'agent_id' => $this->agent->getId(),
            'version' => (string) ($this->latestVersionNumber() + 1),
            'config' => $this->previousWording,
            'changes' => $this->reason,
            'created_by' => $this->resolveEditor(),
            'created_at' => now(),
            // Marks the most recent snapshot, not "the agent's current state" — the agent row is
            // always that. Older snapshots stay put so any of them can be restored.
            'is_active' => true,
        ]);
    }

    /**
     * Numbered from the highest existing version rather than a row count: a deleted snapshot would
     * otherwise hand its number to the next one and two rows would claim the same version.
     */
    private function latestVersionNumber(): int
    {
        $versions = AgentVersion::query()
            ->where('agent_id', $this->agent->getId())
            ->pluck('version')
            ->map(fn (mixed $version): int => (int) $version)
            ->all();

        return $versions === [] ? 0 : max($versions);
    }

    /**
     * The editor is supplied by whoever knows one — a resolver has the request user, a tool has the
     * person it is acting for. This layer does not go looking: reaching for `auth()` here would make
     * a domain action behave differently depending on whether a request happened to be in flight,
     * and it would be wrong exactly when it matters, since the same code runs from queue workers and
     * console commands with nobody logged in.
     *
     * With nobody named, the agent's own user stands in — `created_by` is NOT NULL, and the agent is
     * the one thing every caller has.
     */
    private function resolveEditor(): int
    {
        return $this->editedByUserId
            ?: (int) ($this->agent->user_id ?: $this->agent->created_by_users_id);
    }
}
