<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Actions;

use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentVersion;

/**
 * Snapshot an agent's wording as it stands BEFORE an edit, so a bad one is a copy back.
 *
 * Driven from the observer, not from each write path: a history that only covers the paths someone
 * remembered to instrument is worse than none, because it reads as complete.
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
            // The most recent snapshot, not "current state" — the agent row is always that.
            'is_active' => true,
        ]);
    }

    /**
     * Highest existing version, not a row count — a deleted snapshot would otherwise hand its number
     * to the next one.
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
     * Callers supply the editor; this layer never reaches for `auth()`, which is null exactly where
     * this runs most — queue workers and console commands. The agent's own user stands in otherwise,
     * since `created_by` is NOT NULL.
     */
    private function resolveEditor(): int
    {
        return $this->editedByUserId
            ?: (int) ($this->agent->user_id ?: $this->agent->created_by_users_id);
    }
}
