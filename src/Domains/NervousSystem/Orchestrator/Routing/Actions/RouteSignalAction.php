<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Orchestrator\Routing\Actions;

use Kanvas\NervousSystem\Orchestrator\Routing\DataTransferObject\RoutingDecision;
use Kanvas\NervousSystem\Orchestrator\Signals\DataTransferObject\InboundSignal;
use Kanvas\NervousSystem\Project\Models\Project;

/**
 * The routing cascade end to end: deterministic match (a) → LLM classify (b) → confidence bands
 * → a RoutingDecision the job acts on. A sole deterministic member match auto-forwards without an LLM
 * call; several matches narrow the classifier's pool; no match classifies over all candidates. The
 * classifier's "none" collapses to DROP (no action). Handles one signal to one project — per-segment
 * fan-out loops this over segments.
 */
class RouteSignalAction
{
    private const float AUTO_FORWARD = 0.7;
    private const float APPROVAL_FLOOR = 0.4;

    public function __construct(
        private readonly InboundSignal $signal,
        private readonly string $model = 'gemini-2.5-pro',
    ) {
    }

    /**
     * @param list<Project> $candidates the company's open projects
     */
    public function execute(array $candidates): RoutingDecision
    {
        if ($candidates === []) {
            return RoutingDecision::drop('No candidate projects for this company.');
        }

        $matched = new MatchSignalToProjectsAction($this->signal)->execute($candidates);
        if (count($matched) === 1) {
            return RoutingDecision::forward($matched[0], 1.0, 'Deterministic: a project member attended.');
        }

        // Several deterministic matches → let the classifier pick among them; none → over all candidates.
        $pool = $matched !== [] ? $matched : $candidates;
        $classification = new ClassifySignalToProjectAction($this->signal, $this->model)->execute($pool);

        if ($classification->projectId === null) {
            return RoutingDecision::drop($classification->reason);
        }

        $project = $this->find($pool, $classification->projectId);
        if ($project === null) {
            return RoutingDecision::drop('Classifier returned a project outside the pool.');
        }

        return match (true) {
            $classification->confidence >= self::AUTO_FORWARD
                => RoutingDecision::forward($project, $classification->confidence, $classification->reason),
            $classification->confidence >= self::APPROVAL_FLOOR
                => RoutingDecision::approval($project, $classification->confidence, $classification->reason),
            default
                => RoutingDecision::triage($classification->confidence, $classification->reason),
        };
    }

    /**
     * @param list<Project> $pool
     */
    private function find(array $pool, int $projectId): ?Project
    {
        foreach ($pool as $project) {
            if ($project->getId() === $projectId) {
                return $project;
            }
        }

        return null;
    }
}
