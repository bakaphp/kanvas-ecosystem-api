<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Capability\Services;

use Illuminate\Support\Collection;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\NervousSystem\Capability\Models\AgentSkill;
use Kanvas\NervousSystem\Capability\Models\Skill;
use Kanvas\NervousSystem\Capability\Models\Tool;

/**
 * Unified read-through facade for "what can this agent do right now?"
 *
 * Consumed by:
 * - OpenClaw deploy pipeline (bake-at-deploy mode) — calls
 *   `getActiveSkills($agent)` + `getActiveTools($agent)` and writes
 *   them into the container filesystem at deploy time.
 * - Hot-reload Neuron / Laravel agents that opt in — call on every
 *   invocation.
 * - GraphQL admin views — "show me what this agent has."
 *
 * Skills: still per-agent grants (AgentSkill pivot) with expiry support.
 * Tools: type-based — an agent inherits all active tools from its agent_type.
 *
 * Optional `framework:` argument restricts to capabilities supporting
 * a specific framework provider (`neuron`/`laravel`/`adk`/`openclaw`).
 * If the agent has a `type.provider`, that's used by default.
 */
class CapabilityProvider
{
    /**
     * @return Collection<int, Skill>
     */
    public function getActiveSkills(Agent $agent, ?string $framework = null): Collection
    {
        $framework = $framework ?? $agent->type?->provider;

        /** @var \Illuminate\Database\Eloquent\Collection<int, AgentSkill> $grants */
        $grants = AgentSkill::query()
            ->with('skill')
            ->where('agent_id', $agent->getId())
            ->where('apps_id', $agent->apps_id)
            ->where('companies_id', $agent->companies_id)
            ->active()
            ->notExpired()
            ->get();

        return $grants
            ->filter(fn (AgentSkill $g): bool => $g->skill !== null
                && $g->skill->is_active
                && ! $g->skill->is_deleted)
            ->filter(function (AgentSkill $g) use ($framework): bool {
                if ($framework === null) {
                    return true;
                }
                $frameworks = is_array($g->skill->frameworks) ? $g->skill->frameworks : [];

                return in_array($framework, $frameworks, true);
            })
            ->map(fn (AgentSkill $g): Skill => $g->skill)
            ->values();
    }

    /**
     * Returns the agent's explicitly selected tools.
     * The agent_type tools act as a catalog/guide for selection — not a fallback.
     *
     * @return Collection<int, Tool>
     */
    public function getActiveTools(Agent $agent, ?string $framework = null): Collection
    {
        $framework = $framework ?? $agent->type?->provider;

        $tools = $agent->selectedTools()->active()->get();

        if ($tools->isEmpty()) {
            return collect();
        }

        if ($framework !== null) {
            $tools = $tools->filter(function (Tool $tool) use ($framework): bool {
                $frameworks = is_array($tool->frameworks) ? $tool->frameworks : [];

                return in_array($framework, $frameworks, true);
            });
        }

        return $tools->values();
    }

    /**
     * Returns both skills and tools as a structured array — convenient for
     * deploy scripts and admin dashboards.
     *
     * @return array{skills: Collection<int, Skill>, tools: Collection<int, Tool>}
     */
    public function getActiveCapabilities(Agent $agent, ?string $framework = null): array
    {
        return [
            'skills' => $this->getActiveSkills($agent, $framework),
            'tools' => $this->getActiveTools($agent, $framework),
        ];
    }
}
