<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\AgentRuntime\Harness\Actions;

use Kanvas\Approvals\Actions\RequestApprovalAction;
use Kanvas\Approvals\Enums\ApprovalOriginEnum;
use Kanvas\Approvals\Models\ApprovalRequest;
use Kanvas\Approvals\Repositories\ApprovalPolicyRepository;
use Kanvas\Intelligence\AgentRuntime\Harness\DataTransferObject\HarnessDiff;
use Kanvas\Intelligence\AgentRuntime\Harness\Models\AgentTaskSession;
use Kanvas\NervousSystem\Plan\Models\Task;

/**
 * Asks a human to approve pushing what a session produced.
 *
 * The payload freezes what the approver is agreeing to — the files and the counts at the moment of
 * asking. If the worktree changes afterwards the push still re-checks protected paths, because an
 * approval is consent to a specific diff, not a standing permission.
 *
 * Returns null when this tenant has no push policy configured. That is deliberately visible rather than
 * silent: no policy means no gate, and a job whose work can never be pushed should say so instead of
 * finishing as though someone will be asked.
 */
class RequestPushApprovalAction
{
    public const string APPROVAL_TYPE = 'coding_push';

    public function __construct(
        private readonly AgentTaskSession $session,
        private readonly HarnessDiff $diff,
    ) {
    }

    public function requiresApproval(): bool
    {
        // Attach mode shares one workspace and creates no branch, so there is nothing to push and
        // nothing to approve.
        return $this->session->branch !== null && ! $this->diff->isEmpty();
    }

    public function execute(): ?ApprovalRequest
    {
        if (! $this->requiresApproval()) {
            return null;
        }

        /** @var Task|null $task */
        $task = $this->session->task;

        if ($task === null) {
            return null;
        }

        $policy = ApprovalPolicyRepository::findByType($task, self::APPROVAL_TYPE);

        if ($policy === null) {
            return null;
        }

        return new RequestApprovalAction(
            entity: $task,
            policy: $policy,
            origin: ApprovalOriginEnum::SYSTEM,
            requestedBy: $this->session->user,
            payload: [
                'session_uuid' => $this->session->uuid,
                'repository' => $this->session->repo_slug,
                'branch' => $this->session->branch,
                'summary' => $this->diff->summary(),
                'files' => $this->diff->paths(),
                'model' => $this->session->model,
                'estimated_cost_usd' => $this->session->estimated_cost,
            ],
        )->execute();
    }
}
