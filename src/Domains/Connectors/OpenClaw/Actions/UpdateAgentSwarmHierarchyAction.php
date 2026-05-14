<?php

declare(strict_types=1);

namespace Kanvas\Connectors\OpenClaw\Actions;

use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentSwarmMember;

/**
 * Update an agent's user_context with its swarm hierarchy info
 * (who it reports to, direct reports, role, swarm name).
 */
class UpdateAgentSwarmHierarchyAction
{
    public function __construct(
        protected Agent $agent,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function execute(): array
    {
        $context = $this->buildSwarmContext();

        $existingContext = (string) ($this->agent->user_context ?? '');
        $updatedContext = $this->mergeSwarmSection($existingContext, $context);

        $this->agent->user_context = $updatedContext;
        $this->agent->saveOrFail();

        return [
            'success' => true,
            'message' => 'Agent swarm context updated',
            'agent_id' => $this->agent->getId(),
            'agent_name' => $this->agent->name,
        ];
    }

    protected function buildSwarmContext(): string
    {
        $memberships = AgentSwarmMember::where('agent_id', $this->agent->getId())
            ->where('is_deleted', 0)
            ->with(['swarm', 'reportsTo.agent', 'directReports.agent'])
            ->get();

        if ($memberships->isEmpty()) {
            return '';
        }

        $sections = [];

        foreach ($memberships as $membership) {
            /** @var AgentSwarmMember $membership */
            $swarm = $membership->swarm;
            $lines = [];

            $lines[] = '### ' . (string) $swarm->name;

            if ($membership->role !== null && $membership->role !== '') {
                $lines[] = '**Your role:** ' . $membership->role;
            }

            if ($swarm->description !== null && $swarm->description !== '') {
                $lines[] = '**Team purpose:** ' . $swarm->description;
            }

            $reportsTo = $membership->reportsTo;
            if ($reportsTo) {
                $managerAgent = $reportsTo->agent;
                $managerRole = $reportsTo->role !== null && $reportsTo->role !== ''
                    ? ' (' . $reportsTo->role . ')'
                    : '';
                $lines[] = '**You report to:** ' . (string) $managerAgent->name . $managerRole;
            }

            $directReports = $membership->directReports;
            if ($directReports->isNotEmpty()) {
                $reportNames = $directReports->map(function (AgentSwarmMember $report): string {
                    $role = $report->role !== null && $report->role !== ''
                        ? ' (' . $report->role . ')'
                        : '';

                    return (string) $report->agent->name . $role;
                })->implode(', ');
                $lines[] = '**Your direct reports:** ' . $reportNames;
            }

            $sections[] = implode("\n", $lines);
        }

        return "## Team Structure\n\n" . implode("\n\n", $sections);
    }

    /**
     * Replace the ## Team Structure section in user_context, or append it.
     */
    protected function mergeSwarmSection(string $existingContext, string $swarmSection): string
    {
        if ($swarmSection === '') {
            return (string) preg_replace(
                '/## Team Structure\n.*?(?=\n## |\z)/s',
                '',
                $existingContext,
            );
        }

        if (str_contains($existingContext, '## Team Structure')) {
            return (string) preg_replace(
                '/## Team Structure\n.*?(?=\n## |\z)/s',
                $swarmSection,
                $existingContext,
            );
        }

        $trimmed = rtrim($existingContext);

        return $trimmed !== '' ? $trimmed . "\n\n" . $swarmSection : $swarmSection;
    }
}
