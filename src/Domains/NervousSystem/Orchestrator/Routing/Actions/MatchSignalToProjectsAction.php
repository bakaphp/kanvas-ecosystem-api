<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Orchestrator\Routing\Actions;

use Kanvas\NervousSystem\Orchestrator\Signals\DataTransferObject\InboundSignal;
use Kanvas\NervousSystem\Project\Models\Project;
use Kanvas\NervousSystem\Project\Models\ProjectMember;

/**
 * Cascade step (a): deterministic routing — no LLM. A signal belongs to the project(s) whose members
 * were on the call/thread: match the signal's actor emails against each candidate project's member
 * emails. Returns every matched project (0, 1, or many): one match is a confident route, several feed
 * the LLM classifier to disambiguate, none falls through to classification over all candidates.
 */
class MatchSignalToProjectsAction
{
    public function __construct(
        private readonly InboundSignal $signal,
    ) {
    }

    /**
     * @param iterable<Project> $candidates the company's open projects
     *
     * @return list<Project>
     */
    public function execute(iterable $candidates): array
    {
        $actorEmails = array_flip($this->signal->actorEmails());
        if ($actorEmails === []) {
            return [];
        }

        $byId = [];
        $candidateIds = [];
        foreach ($candidates as $project) {
            $byId[$project->getId()] = $project;
            $candidateIds[] = $project->getId();
        }

        if ($candidateIds === []) {
            return [];
        }

        // One query for all candidate members + their user email; intersect with the signal's actors.
        $members = ProjectMember::query()
            ->whereIn('project_id', $candidateIds)
            ->notDeleted()
            ->with('user:id,email')
            ->get(['id', 'project_id', 'users_id']);

        $matchedIds = [];
        foreach ($members as $member) {
            $email = strtolower(trim((string) ($member->user->email ?? '')));
            if ($email !== '' && isset($actorEmails[$email])) {
                $matchedIds[(int) $member->project_id] = true;
            }
        }

        return array_values(array_intersect_key($byId, $matchedIds));
    }
}
