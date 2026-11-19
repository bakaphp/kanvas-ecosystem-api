<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Orchestrator\Routing\Actions;

use Kanvas\NervousSystem\Orchestrator\Routing\DataTransferObject\RoutingCandidate;
use Kanvas\NervousSystem\Orchestrator\Routing\DataTransferObject\RoutingDecision;
use Kanvas\NervousSystem\Orchestrator\Routing\DataTransferObject\SignalClassification;
use Kanvas\NervousSystem\Orchestrator\Signals\DataTransferObject\InboundSignal;
use Kanvas\NervousSystem\Project\Models\Project;

/**
 * The routing cascade end to end: deterministic match (a) → LLM classify (b) → confidence bands
 * → a RoutingDecision the job acts on.
 *
 * Deterministic matches are the CRM records an outside attendee belongs to (passed in, leads first)
 * plus the projects an attendee is a member of. A sole match forwards without an LLM call. Several
 * matches that include a CRM record always forward: an attendee is evidence, so the classifier only
 * picks among them, and when it can't, the first match wins (lead → organization → project). With no
 * CRM record, several member matches narrow the classifier's pool, none classifies over all
 * candidates, and the confidence bands decide forward / approval / triage. The classifier's "none"
 * collapses to DROP. Handles one signal — per-segment fan-out loops this over segments.
 */
class RouteSignalAction
{
    private const float AUTO_FORWARD = 0.7;
    private const float APPROVAL_FLOOR = 0.4;

    public function __construct(
        private readonly InboundSignal $signal,
        private readonly string $model = 'gemini-3.1-flash-lite',
    ) {
    }

    /**
     * @param list<Project> $projects the company's open projects
     * @param list<RoutingCandidate> $recordMatches CRM records an outside attendee belongs to, in priority order
     */
    public function execute(array $projects, array $recordMatches = []): RoutingDecision
    {
        if ($projects === [] && $recordMatches === []) {
            return RoutingDecision::drop('No candidate projects for this company.');
        }

        $projectMatches = array_map(
            RoutingCandidate::forProject(...),
            new MatchSignalToProjectsAction($this->signal)->execute($projects)
        );

        $matched = [...$recordMatches, ...$projectMatches];
        if (count($matched) === 1) {
            return RoutingDecision::forward($matched[0], 1.0, $this->matchReason($matched[0]));
        }

        $pool = $matched !== [] ? $matched : array_map(RoutingCandidate::forProject(...), $projects);
        $classification = new ClassifySignalAction($this->signal, $this->model)->execute($pool);

        if ($recordMatches !== []) {
            return $this->pickAmongMatches($matched, $classification);
        }

        $project = $classification->candidate?->project();
        if ($project === null) {
            return RoutingDecision::drop($classification->reason);
        }

        return match (true) {
            $classification->confidence >= self::AUTO_FORWARD
                => RoutingDecision::forward(RoutingCandidate::forProject($project), $classification->confidence, $classification->reason),
            $classification->confidence >= self::APPROVAL_FLOOR
                => RoutingDecision::approval($project, $classification->confidence, $classification->reason),
            default
            => RoutingDecision::triage($classification->confidence, $classification->reason),
        };
    }

    /**
     * @param list<RoutingCandidate> $matched
     */
    private function pickAmongMatches(array $matched, SignalClassification $classification): RoutingDecision
    {
        if ($classification->candidate !== null) {
            return RoutingDecision::forward($classification->candidate, $classification->confidence, $classification->reason);
        }

        return RoutingDecision::forward($matched[0], 0.0, sprintf('Several records matched an attendee and none stood out; picked %s by priority.', $matched[0]->key()));
    }

    private function matchReason(RoutingCandidate $match): string
    {
        return $match->project() !== null
            ? 'Deterministic: a project member attended.'
            : sprintf('Deterministic: an attendee belongs to %s.', $match->key());
    }
}
